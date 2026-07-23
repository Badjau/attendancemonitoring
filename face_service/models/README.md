# Face Anti-Spoofing Model

`best_model_quantized.onnx` is the INT8 facenox MiniFASNet V2 SE anti-spoofing
model downloaded from:

https://github.com/facenox/face-antispoof-onnx

The upstream project is licensed under Apache-2.0. The model expects a cropped
RGB face letterboxed to `128x128` and returns two logits:

- index `0`: real
- index `1`: spoof
