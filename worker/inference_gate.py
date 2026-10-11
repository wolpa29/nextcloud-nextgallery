"""One inference at a time per model host, across independent processes."""
import fcntl,hashlib,time,urllib.parse,threading
from contextlib import contextmanager
from pathlib import Path

class InferenceCancelled(Exception):
    """A paused pipeline leaves a waiting slot without marking its media as failed."""

_local=threading.local()

@contextmanager
def hold(directory,url,should_stop=lambda:False):
    if not directory:
        yield;return
    host=urllib.parse.urlsplit(url).hostname or url
    held=getattr(_local,'hosts',set())
    if host in held:
        yield;return
    name='inference-'+hashlib.sha256(host.lower().encode()).hexdigest()+'.lock'
    with (Path(directory)/name).open('a') as lock:
        while True:
            if should_stop():raise InferenceCancelled()
            try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);break
            except BlockingIOError:time.sleep(.1)
        _local.hosts=held|{host}
        try:yield
        finally:
            _local.hosts=held
            fcntl.flock(lock,fcntl.LOCK_UN)
