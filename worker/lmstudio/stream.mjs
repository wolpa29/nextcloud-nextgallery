// Official SDK image overrides; OpenAI-compatible HTTP ignores these settings.
import {LMStudioClient, Chat} from '@lmstudio/sdk';
import {once} from 'node:events';
import {buildHistory, predictionConfig, finishReason, requestFailure} from './protocol.mjs';

const quiet = {info(){}, error(){}, warn(){}, debug(){}};
let prediction;
let cancelled = false;
process.on('SIGTERM', async () => {
  cancelled = true;
  const force = setTimeout(() => process.exit(1), 1000);
  force.unref();
  try { await prediction?.cancel(); } finally { process.exit(1); }
});
async function write(text) {
  if (!process.stdout.write(text)) await once(process.stdout, 'drain');
}
const event = value => write(`data: ${JSON.stringify(value)}\n\n`);
try {
  let input = '';
  for await (const chunk of process.stdin) input += chunk;
  const request = JSON.parse(input);
  const client = new LMStudioClient({baseUrl:request.base_url, apiToken:request.api_token || undefined, logger:quiet});
  const model = await client.llm.model(request.payload.model);
  const history = await buildHistory(client, request.payload.messages);
  if (cancelled) process.exit(1);
  prediction = model.respond(Chat.from(history), predictionConfig(request.payload));
  for await (const fragment of prediction) {
    const channel = fragment.reasoningType === 'none' ? 'content' : 'reasoning_content';
    await event({choices:[{index:0, delta:{[channel]:fragment.content}}]});
  }
  const result = await prediction.result();
  const stop = result.stats.stopReason;
  const finish = finishReason(stop);
  await event({choices:[{index:0, delta:{}, finish_reason:finish}], usage:{
    prompt_tokens:result.stats.promptTokensCount, completion_tokens:result.stats.predictedTokensCount,
    total_tokens:result.stats.totalTokensCount}});
  await write('data: [DONE]\n\n');
  process.exit(0);
} catch (error) {
  // No endpoint, prompt, image path or authentication data in worker diagnostics.
  await event({error:requestFailure(error)});
  process.exit(1);
}
