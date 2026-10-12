# Set up an external embedding service

[Deutsche Anleitung](EXTERNAL_EMBEDDING_SERVER.md)

The external service computes image and search-text vectors. Nextcloud stores published vectors and performs the search. Descriptions and OCR remain independent processing stages.

Choose **automatic Gemma installation** or **your own model**. Custom models are not restricted to Gemma or Qwen. Images and text must be embedded into the same vector space, and the service must implement the API described below.

## 1. Install Gemma on the external computer

Requirements: Python 3.10 or later, Docker with Compose, and enough storage for the image, model and runtime. GPU inference also requires NVIDIA drivers and NVIDIA Container Toolkit. Nextcloud must be able to reach the selected port.

```bash
git clone https://github.com/wolpa29/nextcloud-nextgallery.git
cd nextcloud-nextgallery
./scripts/install-embedding-server.sh --language en
```

Without a language flag, the interactive wizard first asks you to choose **German or English**. On Windows, use the same Python wizard:

```powershell
py -3 scripts/install-embedding-server.py --interactive --language en
```

The wizard asks for the model, hardware and port:

- **Original (Full):** CPU or NVIDIA GPU, approximately 1.5 GB of model downloads plus the runtime. RAM/VRAM needs depend on images and the runtime.
- **Lite:** CPU, approximately 388 MB of model downloads.
- On a computer with several GPUs, select a specific card. Its number identifies the host GPU; when Docker exposes only that card, its CUDA number inside the container is 0.
- The default port is **9379**. Allow access only from the required computers. For remote connections across networks, use HTTPS through a suitable reverse proxy.

The script creates private connection details in `~/.nextgallery-embedding-server`. Reconfiguring reuses the API key and model volume. Keep the key private; do not publish the complete connection summary.

## 2. Check readiness

A started container may still be downloading or loading its model. The check confirms that both image and text requests work:

```bash
python3 scripts/install-embedding-server.py --language en --action check
```

The check uses only a synthetic search text and a small synthetic image. It changes no Nextcloud settings or saved vectors. It verifies the API, not search quality or compatibility with existing vectors.

If the service does not respond yet, inspect download/loading progress and repeat the check once loading finishes:

```bash
docker compose --env-file ~/.nextgallery-embedding-server/.env \
  -f ~/.nextgallery-embedding-server/compose.json logs --tail=50
```

## 3. Connect it in NextGallery

Open **NextGallery → Settings → Visual image search · Embeddings**:

| Setting | Value |
| --- | --- |
| Embedding service | External embedding server |
| Model family | EmbeddingGemma 2, Qwen3-VL-Embedding or Custom model |
| Model ID | Exact ID reported by the service; Gemma Full uses `embeddinggemma-2-full`, Lite uses `embeddinggemma-2` |
| API address | `http://SERVER-IP:9379/v1` or the direct HTTPS address |
| API key | The service’s key |

1. Select **Test connection** before saving. This uses the current form values and also checks compatibility with existing vector spaces.
2. Before saving, pause affected embedding runs under **User runs** and let the current file finish. The interface explains why saving is blocked. Descriptions and OCR can continue during an embedding-only change.
3. Enable **Activate for users** and save. Proven compatible vectors are kept. A different vector space requires an explicitly confirmed rebuild.

An unreachable service does not prove a model change. Do not delete vectors because of a connection error.

<a id="custom-models"></a>
## Custom models

In the wizard, choose **Custom model / check an existing service**; in NextGallery, choose **Model family → Custom model**. You can use any model ID, provided it exactly matches the service’s reported ID. The service may report its own family name; `gemma2` and `qwen3_vl` are not required for this option.

### Connect an existing model server

Install and start your chosen model with its intended runtime. The service must implement both endpoints below. If its native API differs, provide an adapter. A text-only embedding endpoint or a chat/image-description API cannot provide the required image search vectors.

The installer can test your existing service with image and text requests. It does not install Gemma, replace Docker configuration or save the key you enter. Then copy the URL, model ID and API key into NextGallery.

### Serve your model through the NextGallery adapter

Model developers and operators can use the [adapter template](examples/custom_embedding_adapter.py). Implement `load_backend()` using your chosen model’s runtime, and install its dependencies in a dedicated Python environment, including Pillow. Image and text outputs must share the same vector space.

The template refuses to start until a real model is connected. `backend.identity` must reproducibly describe weights/model revision, runtime versions, preprocessing, precision and task prompts. Change it when these change. The adapter code is also included in the vector-space fingerprint.

```bash
# Set NEXTGALLERY_EMBEDDING_KEY as a private environment variable first.
/path/to/model-venv/bin/python worker/embedding_server.py \
  --family custom --model 'provider/my-image-text-model' \
  --model-path /path/to/model --custom-adapter /path/to/adapter.py \
  --device cuda --host 0.0.0.0 --port 9379
```

Use `--device cpu` if your adapter supports CPU inference. The adapter is explicitly selected local Python code loaded when the service starts. NextGallery does not download executable model adapters during a web request. Runtime installation remains model-specific.

### API contract

When a key is configured, requests use `Authorization: Bearer <API-key>`. No redirects. Requests contain image bytes or search text, never Nextcloud file paths.

**`GET /v1/info`** returns, for example:

```json
{
  "model": "provider/my-image-text-model",
  "family": "my_model_family",
  "dimensions": 512,
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "modalities": ["text", "image"]
}
```

Replace the example `space` value with a real, stable SHA-256 fingerprint. Supported dimensions range from 128 to 4096. Change `space` whenever weights, runtime, precision, preprocessing or task prompts change the vector space. A matching model name or dimension alone is insufficient.

**`POST /v1/embed`** accepts exactly one input:

```json
{"model":"provider/my-image-text-model","text":"a red car"}
```

```json
{"model":"provider/my-image-text-model","image":"BASE64_ENCODED_IMAGE_BYTES"}
```

The response contains `{"space":"<same fingerprint>","embedding":[...]}` with exactly the declared number of finite numbers and a nonzero vector. NextGallery normalizes vectors. Video frames are embedded individually.

## Operation and troubleshooting

| Action | Command |
| --- | --- |
| Check readiness | `python3 scripts/install-embedding-server.py --language en --action check` |
| Show connection details | `python3 scripts/install-embedding-server.py --language en --action info` |
| Start / repair an old GPU mapping | `python3 scripts/install-embedding-server.py --language en --action start` |
| Stop | `python3 scripts/install-embedding-server.py --language en --action stop` |
| Uninstall, keep models | `python3 scripts/install-embedding-server.py --language en --action uninstall` |
| Also delete model downloads | Add `--delete-models` only when deletion is explicitly intended |

**`invalid device ordinal`:** Update with `git pull --ff-only`, then run `--action start`. This repairs the old host/container GPU mapping while preserving the key, profile and model volume.

**Docker cannot expose NVIDIA GPUs:** The wizard offers NVIDIA Container Toolkit setup. On supported Ubuntu/Debian systems it can configure it with administrator privileges. Restarting Docker may also affect other containers. If the toolkit is already installed:

```bash
sudo nvidia-ctk runtime configure --runtime=docker
sudo systemctl restart docker
```

**401/403:** Check the API key. **Connection refused:** Inspect container status and logs. **Timeout:** Check loading progress, address, allowed port and firewall. A local readiness check does not establish network reachability from Nextcloud; use **Test connection** there as well.

## The same model on CPU and GPU

A model name alone does not establish an identical vector space. The Original profile fingerprints the model directory and versions of PyTorch, Transformers and Sentence Transformers. NextGallery also includes image resolution and video-frame count.

The Full runtime uses FP32 on CPU and FP16 on CUDA. This precision choice is currently not separately represented in its vector-space ID. A successful ID comparison is not a numerical-equivalence measurement. If IDs differ, compare model revisions, runtimes and processing settings before changing saved vectors; keep the compatibility check in place.
