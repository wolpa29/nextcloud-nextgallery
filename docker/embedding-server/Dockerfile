FROM python:3.13-slim-bookworm
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /service
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 libgomp1 && rm -rf /var/lib/apt/lists/*
COPY worker/embedding_server.py worker/embedding_setup.py worker/embedding-full-model.json worker/requirements.txt worker/requirements-embeddings.txt worker/requirements-embeddings-full.txt ./
COPY docker/embedding-server/start.py ./start.py
RUN mkdir /data && chown 10001:10001 /data
USER 10001:10001
EXPOSE 9379
ENTRYPOINT ["python", "/service/start.py"]
