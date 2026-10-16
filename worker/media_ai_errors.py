"""Shared errors distinguish unavailable services from invalid media/results."""


class PipelineError(RuntimeError):
    pass


class ServiceUnavailable(PipelineError):
    """Stop the run without charging the current file a failed attempt."""
    def __init__(self, message='', status=None):
        super().__init__(message)
        self.status = status  # HTTP status when the service answered; None if unreachable.

