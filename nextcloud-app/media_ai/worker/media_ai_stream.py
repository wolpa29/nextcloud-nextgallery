"""OpenAI-compatible SSE reader and incremental repetition guard."""
from media_ai_budget import check_budget
from collections import deque, Counter
import json
import re
import time
from http.client import HTTPException


class StreamFailure(RuntimeError):
    def __init__(self, reason, response=None, channel=None):
        super().__init__(reason)
        self.response = response or {}
        self.channel = channel


class StreamLoop(StreamFailure):
    pass


class RepetitionGuard:
    """Count consecutive blocks across arbitrary transport/token boundaries."""
    def __init__(self, repetitions=5, numeric_repetitions=40):
        self.repetitions = repetitions
        self.numeric_repetitions = numeric_repetitions
        self.pending = ''
        self.history = deque(maxlen=256)
        self.runs = [0] * 257
        self.previous_label = None
        self.label_run = 0
        self.line_parts = []
        self.line_tail = ''
        self.previous_line = []
        self.line_run = 1
        self.line_grams = deque(maxlen=24)
        self.gram_counts = Counter()

    def feed(self, fragment, final=False):
        text=self.line_tail+fragment.casefold()
        self.line_tail='\\' if text.endswith('\\') else ''
        if self.line_tail:text=text[:-1]
        lines = re.split(r'\\n|\n', text)
        if len(lines)>1:
            lines[0]=''.join(self.line_parts)+lines[0]
            self.line_parts=[]
        self.line_parts.append(lines.pop())
        if final:
            lines.append(''.join(self.line_parts)+self.line_tail)
            self.line_parts=[]
            self.line_tail=''
        for line in lines:
            words = re.findall(r'\w+', line)
            if not words: continue
            # Detect the same long phrase across changing sentence openings/endings.
            # One occurrence per line; bounded window keeps unrelated distant text out.
            segments=([words] if len(words)<=256 else [words[:128],words[-128:]]) if not any(c.isdigit() for c in line) else []
            grams=set()
            for segment in segments:
                for pos in range(len(segment)-6):
                    gram=tuple(segment[pos:pos+7])
                    # Numeric/code tables often repeat real templates (IP links,
                    # timesheets). This fuzzy rule covers natural-language phrases.
                    if all(word.isalpha() for word in gram) and sum(map(len,gram))>=24:
                        grams.add(gram)
            if len(self.line_grams)==self.line_grams.maxlen:
                for gram in self.line_grams.popleft():
                    self.gram_counts[gram]-=1
                    if self.gram_counts[gram]==0:del self.gram_counts[gram]
            self.line_grams.append(grams)
            self.gram_counts.update(grams)
            if any(self.gram_counts[gram]>=self.repetitions for gram in grams):
                raise StreamLoop('Wiederholungsschleife im Stream erkannt (gleicher langer Satzteil in '
                    +str(self.repetitions)+' Zeilen trotz wechselnder Formulierungen).')
            common=0
            for left,right in zip(words,self.previous_line):
                if left!=right: break
                common+=1
            similar=(common>=8 and common>=0.8*max(len(words),len(self.previous_line))
                     and sum(map(len,words[:common]))>=40)
            self.line_run=self.line_run+1 if similar else 1
            self.previous_line=words
            if self.line_run>=self.repetitions:
                raise StreamLoop('Wiederholungsschleife im Stream erkannt (aehnliche Zeilen mit wiederholtem Satzanfang).')
        self.pending += fragment.casefold()
        # A loop may concatenate words without ever producing a delimiter.
        tail = self.pending[-max(160, self.repetitions*32):]
        for period in range(4,33):
            length = max(64, self.repetitions*period)
            count = (length+period-1)//period
            if len(tail) >= count*period:
                block = tail[-period:]
                if sum(c.isalpha() for c in block) >= 4 and tail[-count*period:] == block*count:
                    raise StreamLoop('Wiederholungsschleife im Stream erkannt (verketteter Text ohne Wortgrenzen).')
        matches = list(re.finditer(r'\w+|[^\w\s]', self.pending))
        consumed = 0
        for match in matches:
            if not final and match.end() == len(self.pending) and re.fullmatch(r'\w+', match.group()):
                break
            self.token(match.group())
            consumed = match.end()
        self.pending = self.pending[consumed:]

    def token(self, token):
        for period in range(1, len(self.history) + 1):
            self.runs[period] = self.runs[period] + 1 if token == self.history[-period] else 0
            if self.runs[period] + period < self.repetitions * period:
                continue
            block = list(self.history)[-period + 1:] + [token] if period > 1 else [token]
            words = [word for word in block if re.search(r'[^\W\d_]', word)]
            if sum(map(len, words)) >= 4:
                raise StreamLoop('Wiederholungsschleife im Stream erkannt (gleicher Textblock mindestens '
                                 + str(self.repetitions) + ' Mal hintereinander).')
        self.history.append(token)
        # Counters need a higher threshold: real boards can have JUSB1..JUSB8.
        label = re.fullmatch(r'([^\W\d_]*)(\d+)([^\W\d_]*)', token)
        if label and (label[1] or label[3]):
            current = (label[1], int(label[2]), label[3])
            previous = self.previous_label
            follows = previous and current[0] == previous[0] and current[2] == previous[2] and current[1] == previous[1] + 1
            self.label_run = self.label_run + 1 if follows else 1
            self.previous_label = current
            if self.label_run >= self.numeric_repetitions:
                raise StreamLoop('Wiederholungsschleife im Stream erkannt (fortlaufende Zahlen-Labels).')
        elif re.search(r'\w', token) and token != 'n':
            self.previous_label = None
            self.label_run = 0


def chat_stream(open_response, url, payload, headers, timeout, repetitions=5, numeric_repetitions=40, on_progress=None):
    """Closing the response signals cancellation when a guard raises."""
    content, reasoning = [], []
    guards = {channel: RepetitionGuard(repetitions, numeric_repetitions) for channel in ('content', 'reasoning_content')}
    usage, finish, tool_calls, done = {}, None, False, False
    last_progress = time.monotonic()

    def assembled():
        return {'choices': [{'finish_reason': finish, 'message': {
            'content': ''.join(content), 'reasoning_content': ''.join(reasoning),
            **({'tool_calls': True} if tool_calls else {})}}], 'usage': usage, 'streamed': True}

    with open_response(url, 'POST', json.dumps(payload).encode(), headers, timeout) as response:
        if 'text/event-stream' not in response.headers.get('Content-Type', '').lower():
            # Some compatible endpoints ignore stream=true; retain explicit evidence.
            check_budget()
            wire = response.read(2 * 1024 * 1024 + 1)
            check_budget()
            if len(wire) > 2 * 1024 * 1024:
                raise StreamFailure('Nicht gestreamte Modellantwort zu gross.')
            result = json.loads(wire)
            result['streamed'] = False
            if not isinstance(result, dict): raise StreamFailure('Ungueltiges Modellantwort-Objekt.')
            choices = result.get('choices') or [{}]
            if not isinstance(choices, list) or not isinstance(choices[0], dict):
                raise StreamFailure('Ungueltige Modellantwort-Auswahl.', result)
            message = choices[0].get('message') or {}
            if not isinstance(message, dict): raise StreamFailure('Ungueltige Modellnachricht.', result)
            for channel, guard in guards.items():
                text = message.get(channel) or ''
                if not isinstance(text, str):
                    raise StreamFailure('Ungueltiger Modelltext.', result, channel)
                try:
                    guard.feed(text, final=True)
                except StreamLoop as error:
                    error.response, error.channel = result, channel
                    raise
            return result
        event_lines = []
        while True:
            check_budget()
            try:
                line = response.readline(1024 * 1024 + 1)
            except (OSError, HTTPException) as error:
                raise StreamFailure('Modellstream unterbrochen: '+str(error), assembled()) from None
            check_budget()
            if not line:
                break
            if len(line) > 1024 * 1024:
                raise StreamFailure('Ein einzelnes Stream-Ereignis ist zu gross.', assembled())
            line = line.decode('utf-8').rstrip('\r\n')
            if line.startswith('data:'):
                event_lines.append(line[5:].lstrip())
                continue
            if line or not event_lines:
                continue
            data = '\n'.join(event_lines)
            event_lines = []
            if data == '[DONE]':
                done = True
                break
            event = json.loads(data)
            if not isinstance(event, dict): raise StreamFailure('Ungueltiges Modellstream-Objekt.', assembled())
            if 'error' in event:
                raise StreamFailure('Modellserver meldet einen Stream-Fehler.', assembled())
            if isinstance(event.get('usage'), dict):
                usage = event['usage']
            choices = event.get('choices') or []
            if not isinstance(choices, list): raise StreamFailure('Ungueltige Stream-Auswahl.', assembled())
            for choice in choices:
                if not isinstance(choice, dict): raise StreamFailure('Ungueltige Stream-Auswahl.', assembled())
                if choice.get('index', 0) != 0:
                    continue
                delta = choice.get('delta') or {}
                if not isinstance(delta, dict): raise StreamFailure('Ungueltiges Stream-Delta.', assembled())
                if delta.get('tool_calls'):
                    tool_calls = True
                if choice.get('finish_reason') is not None:
                    finish = choice['finish_reason']
                for channel, parts in (('content', content), ('reasoning_content', reasoning)):
                    text = delta.get(channel)
                    if channel == 'reasoning_content' and text is None:
                        text = delta.get('reasoning')
                    if text is None:
                        continue
                    if not isinstance(text, str):
                        raise StreamFailure('Ungueltiges Textfragment im Modellstream.', assembled(), channel)
                    parts.append(text)
                    try:
                        guards[channel].feed(text)
                    except StreamLoop as error:
                        error.response, error.channel = assembled(), channel
                        raise
            if on_progress and time.monotonic()-last_progress >= 15:
                last_progress=time.monotonic()
                on_progress(assembled())
        if not done:
            raise StreamFailure('Modellstream endete ohne Abschlussmarkierung.', assembled())
        for channel, guard in guards.items():
            try:
                guard.feed('', final=True)
            except StreamLoop as error:
                error.response, error.channel = assembled(), channel
                raise
    return assembled()
