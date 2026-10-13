# Technical Reference: External Embedding Service

[Deutsche Referenz](EMBEDDING_SERVICE_REFERENCE.md) · [Main guide](EXTERNAL_EMBEDDING_SERVER.en.md)

This reference documents the technical interface, API contract, custom model integration, CLI management commands, and troubleshooting for the external NextGallery embedding server.

## Installation notes

- Original: about 1.52 GB of model downloads plus runtime, roughly 4–6 GB of RAM/VRAM depending on inputs. Lite: about 388 MB plus runtime, about 2 GB RAM; CPU only.
- Selecting one GPU keeps its host ID in Docker’s reservation. Inside the container, the exposed GPU has CUDA index 0.
- The installer stores private connection details in `~/.nextgallery-embedding-server`. Reconfiguration reuses the API key and model volume. Keep the key private.
- Nextcloud must reach the selected port. Restrict access to the required computers; use HTTPS for connections across networks.
- The adapter template refuses to start until `load_backend()` is implemented using a real model.

---

## 1. Connecting Custom Models

NextGallery does not restrict external services to Gemma or Qwen. You can use any model provided it projects both text and image queries into the same shared vector space and implements the API contract specified below.

### Using an Existing Model Server

If you already operate an existing model server:
- The server must provide both endpoints: `GET /v1/info` and `POST /v1/embed`.
- A text-only embedding endpoint or a chat/image-description API cannot provide the vectors required for visual image search.
- If your server's native API differs, place a lightweight adapter in front of it.
- Start the installer and choose **Custom model** to enter your service’s URL, model ID and key:
  ```bash
  ./scripts/install-embedding-server.sh
  ```
  This test issues synthetic text and image requests, does not install Gemma, does not alter existing Docker configurations, and does not permanently store the key.

### Serving a Custom Model via the NextGallery Adapter

For custom models, an adapter template is provided in [examples/custom_embedding_adapter.py](examples/custom_embedding_adapter.py).

1. Implement the `load_backend()` function in your adapter file.
2. Install model dependencies (including Pillow) in a dedicated Python virtual environment (`venv`).
3. `backend.identity` must reproducibly describe model weights, revision, runtime versions, preprocessing, precision, and task prompts. If any of these factors change, the identity must change, as the adapter code is incorporated into the vector-space fingerprint.
4. Start the service with the custom adapter:

```bash
# Set NEXTGALLERY_EMBEDDING_KEY as an environment variable first
/path/to/model-venv/bin/python worker/embedding_server.py \
  --family custom \
  --model 'provider/my-image-text-model' \
  --model-path /path/to/model \
  --custom-adapter /path/to/adapter.py \
  --device cuda \
  --host 0.0.0.0 \
  --port 9379
```

Use `--device cpu` to execute inference on the CPU if supported by your adapter. Adapter code is loaded locally at startup; NextGallery never downloads executable code over network requests.

---

## 2. API Contract

When an API key is configured, all requests require the HTTP header:
```http
Authorization: Bearer <API-Key>
```
Redirects (HTTP redirects) are not supported. Requests contain raw bytes or search text, never internal Nextcloud file paths.

### `GET /v1/info`

Returns metadata about the model and its vector space:

```json
{
  "model": "provider/my-image-text-model",
  "family": "my_model_family",
  "dimensions": 512,
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "modalities": ["text", "image"]
}
```

- **`dimensions`**: Supported range is 128 to 4096 dimensions.
- **`space`**: Stable 64-character SHA-256 fingerprint. Whenever weights, runtime, precision, preprocessing, or task prompts alter the vector space, this fingerprint must change. Matching model names or dimensions alone are not sufficient.
- **`modalities`**: Must include `["text", "image"]`.

### Optional image batches

Services with real model batching additionally advertise `"image_batch": true` and `"max_batch_size": 64` in `/v1/info`. NextGallery uses this capability for external services only. Without it, individual requests remain in use.

A batch sends `{ "model": "...", "images": ["BASE64_PNG_1", "BASE64_PNG_2"] }` to `/v1/embed`. The response contains `{ "space": "...", "embeddings": [[...], [...]] }`: exactly one normalized vector per input, in the same order. `text`, `image` and `images` are alternative input forms. Existing authentication and the 24 MiB limit still apply. Independent batched images are not a single combined multimodal input.

### `POST /v1/embed`

Accepts exactly one input payload (`text`, `image`, or the optional `images` batch):

**Search text:**
```json
{
  "model": "provider/my-image-text-model",
  "text": "a red car"
}
```

**Image (Base64-encoded):**
```json
{
  "model": "provider/my-image-text-model",
  "image": "BASE64_ENCODED_IMAGE_BYTES"
}
```

**Response:**
```json
{
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "embedding": [0.0123, -0.0456, 0.0789, ...]
}
```

- The `embedding` array must contain exactly the declared number of finite floating-point numbers and must not be a zero vector.
- NextGallery normalizes vectors for cosine similarity search.
- Video files are processed by extracting and embedding individual still frames.

---

## 3. Operation and Troubleshooting

### CLI Commands

All operational tasks can be performed using `scripts/install-embedding-server.py`:

| Action | Command | Description |
| --- | --- | --- |
| Check readiness | `python3 scripts/install-embedding-server.py --action check` | Tests image and text requests using synthetic data |
| Show connection info | `python3 scripts/install-embedding-server.py --action info` | Displays URL, port, model ID, and API key |
| Start / repair GPU mapping | `python3 scripts/install-embedding-server.py --action start` | Starts container and repairs legacy GPU device mappings |
| Stop service | `python3 scripts/install-embedding-server.py --action stop` | Stops the running container instance |
| Uninstall (keep models) | `python3 scripts/install-embedding-server.py --action uninstall` | Removes containers while preserving downloaded models |
| Full uninstall | `python3 scripts/install-embedding-server.py --action uninstall --delete-models` | Removes containers and deletes downloaded models |

You can optionally append `--language de` or `--language en` to any command.

<a id="troubleshooting"></a>
### Common Issues and Resolutions

- **`invalid device ordinal`:** Occurs when an older container configuration references a host GPU ID directly inside the container. Resolution: update the repository with `git pull --ff-only` and execute `python3 scripts/install-embedding-server.py --action start`. This automatically corrects the GPU mapping while preserving your API key, profile, and model volume.
- **NVIDIA GPU unavailable in Docker container:** The NVIDIA Container Toolkit may be missing or not configured for Docker. The wizard offers setup on supported Ubuntu/Debian systems. If already installed, configure it below; restarting Docker also affects other containers:
  ```bash
  sudo nvidia-ctk runtime configure --runtime=docker
  sudo systemctl restart docker
  ```
- **HTTP 401 / 403:** The API key entered in NextGallery settings does not match the key in `~/.nextgallery-embedding-server/.env`.
- **Connection refused:** The container is not running or still initializing. Check logs:
  ```bash
  docker compose --env-file ~/.nextgallery-embedding-server/.env \
    -f ~/.nextgallery-embedding-server/compose.json logs --tail=50
  ```
- **Timeout:** The model is still downloading or loading into memory, or a firewall is blocking port `9379`. Note that a successful local `--action check` does not guarantee network accessibility from the Nextcloud server. Always use the **Test connection** button in NextGallery as well.

---

## 4. The Same Model on CPU and GPU

Identical model names do not guarantee identical vector spaces:
- In the standard profile, the files in the model directory and installed versions of PyTorch, Transformers, and Sentence Transformers are incorporated into the vector-space ID (`space`).
- The Full model uses FP32 on CPU and NVIDIA CUDA. FP16 is unsuitable for EmbeddingGemma 2. TF32 is disabled on CUDA so computation also uses FP32.
- Official PyTorch CPU and CUDA packages of the same release share a space ID. The existing FP32 CPU identity is retained; different library versions, model files and processing settings remain distinct. `/v1/info` reports the installed library versions in `runtime`.
- Rebuild and restart older GPU services using the updated code, then check the connection in NextGallery. Existing CPU vectors can be retained when compatibility is confirmed.
- Space ID matching is a safeguard, not a guarantee of bit-exact numerical equality.
- If space IDs differ, never bypass the compatibility safeguard or prematurely delete vectors. First check model versions, library runtimes, and preprocessing settings. An unreachable server does not imply a model change.
