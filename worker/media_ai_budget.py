"""One elapsed-time budget shared by all stages and retries of one image."""
from contextlib import contextmanager
from contextvars import ContextVar
import socket
import threading
import time


class ImageTimeout(RuntimeError):
    pass


_current = ContextVar('media_ai_image_budget', default=None)


@contextmanager
def track_cancel(callback):
    budget = _current.get()
    if budget is not None:
        with budget.lock:
            budget.callbacks.add(callback)
    try:
        check_budget()
        yield
        check_budget()
    except Exception:
        check_budget()
        raise
    finally:
        if budget is not None:
            with budget.lock:
                budget.callbacks.discard(callback)


def check_budget():
    budget = _current.get()
    if budget is not None:
        budget.check()


def network_timeout(timeout):
    budget = _current.get()
    return min(timeout, budget.remaining()) if budget is not None and budget.seconds else timeout


@contextmanager
def track_response(response):
    budget = _current.get()
    # urllib HTTP(S) responses expose their socket here. Shutdown interrupts a
    # blocked read as well as a constantly trickling SSE stream; close() alone
    # can wait for the reader's buffered-I/O lock.
    sock = getattr(getattr(getattr(response, 'fp', None), 'raw', None), '_sock', None)
    if budget is not None and sock is not None:
        with budget.lock:
            budget.sockets.add(sock)
    try:
        check_budget()
        yield response
        check_budget()
    except Exception:
        check_budget()  # Report elapsed image time, not a misleading EOF/network error.
        raise
    finally:
        if budget is not None and sock is not None:
            with budget.lock:
                budget.sockets.discard(sock)


class ImageBudget:
    def __init__(self, seconds):
        self.seconds = float(seconds)
        if self.seconds < 0:
            raise ValueError('Image time budget must be nonnegative')
        self.lock = threading.Lock()
        self.sockets = set()
        self.callbacks = set()
        self.timer = None
        self.expired = False

    def __enter__(self):
        self.deadline = time.monotonic() + self.seconds if self.seconds else None
        self.token = _current.set(self)
        if self.seconds:
            self.timer = threading.Timer(self.seconds, self.interrupt)
            self.timer.daemon = True
            self.timer.start()
        return self

    def interrupt(self):
        with self.lock:
            self.expired = True
            for sock in self.sockets:
                try:
                    sock.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
            callbacks = list(self.callbacks)
        for callback in callbacks:
            try:
                callback()
            except Exception:
                pass

    def check(self):
        if self.seconds and (self.expired or time.monotonic() >= self.deadline):
            raise ImageTimeout(f'Zeitgrenze pro Bild überschritten ({self.seconds:g} s).')

    def remaining(self):
        self.check()
        return max(0.001, self.deadline - time.monotonic())

    def __exit__(self, kind, value, traceback):
        if self.timer is not None:
            self.timer.cancel()
            self.timer.join()
        _current.reset(self.token)
        if kind is None:
            self.check()
