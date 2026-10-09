"""Shared errors distinguish unavailable services from invalid media/results."""


class PipelineError(RuntimeError):
    pass


class ServiceUnavailable(PipelineError):
    """Stop the run without charging the current file a failed attempt."""

