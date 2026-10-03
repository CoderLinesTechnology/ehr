#!/usr/bin/env python3
"""Compare a rendered screenshot with its comp. Development only.

  python tools/visual/compare.py --comp docs/design/comps/01-appointments.png --shot /tmp/shot.png \
      --out /tmp/cmp-01 [--region x,y,w,h ...]

Writes <out>-side.png (comp | render | diff heatmap) and <out>-region-<i>.png (comp above render,
x2 zoom) and prints JSON metrics — mean absolute error, % pixels differing (>24/255 on any channel)
and a tile SSIM — globally and per region, so you can see WHERE a render deviates.
"""
import argparse, json
import numpy as np
from PIL import Image


def load(path, size=None):
    im = Image.open(path).convert("RGB")
    if size and im.size != size:
        canvas = Image.new("RGB", size, (255, 255, 255))
        canvas.paste(im.crop((0, 0, min(im.width, size[0]), min(im.height, size[1]))), (0, 0))
        im = canvas
    return im


def ssim(a, b, t=32):
    lum = lambda x: 0.299 * x[..., 0] + 0.587 * x[..., 1] + 0.114 * x[..., 2]
    A, B = lum(a.astype(np.float64)), lum(b.astype(np.float64))
    C1, C2 = (0.01 * 255) ** 2, (0.03 * 255) ** 2
    vals = []
    for y in range(0, A.shape[0] - t + 1, t):
        for x in range(0, A.shape[1] - t + 1, t):
            pa, pb = A[y:y + t, x:x + t], B[y:y + t, x:x + t]
            ma, mb = pa.mean(), pb.mean()
            cov = ((pa - ma) * (pb - mb)).mean()
            vals.append(((2 * ma * mb + C1) * (2 * cov + C2)) / ((ma ** 2 + mb ** 2 + C1) * (pa.var() + pb.var() + C2)))
    return float(np.mean(vals)) if vals else 1.0


def metrics(a, b):
    d = np.abs(a.astype(np.int16) - b.astype(np.int16))
    return {"mae": round(float(d.mean()), 2), "pct_diff": round(float((d.max(axis=2) > 24).mean() * 100), 2), "ssim": round(ssim(a, b), 4)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--comp", required=True)
    ap.add_argument("--shot", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--region", action="append", default=[])
    a = ap.parse_args()

    comp = load(a.comp)
    shot = load(a.shot, comp.size)
    A, B = np.asarray(comp), np.asarray(shot)
    result = {"size": comp.size, "global": metrics(A, B), "regions": []}

    d = np.abs(A.astype(np.int16) - B.astype(np.int16)).max(axis=2)
    heat = np.zeros_like(A)
    heat[..., 0] = np.clip(d * 3, 0, 255)
    heat[..., 1] = (A.mean(axis=2) * 0.25).astype(np.uint8)
    heat[..., 2] = (A.mean(axis=2) * 0.25).astype(np.uint8)
    side = Image.new("RGB", (comp.width * 3, comp.height), (255, 255, 255))
    side.paste(comp, (0, 0)); side.paste(shot, (comp.width, 0)); side.paste(Image.fromarray(heat.astype(np.uint8)), (comp.width * 2, 0))
    side.save(a.out + "-side.png")

    for i, r in enumerate(a.region):
        x, y, w, h = [int(v) for v in r.split(",")]
        m = metrics(A[y:y + h, x:x + w], B[y:y + h, x:x + w]); m["region"] = r
        result["regions"].append(m)
        pair = Image.new("RGB", (w * 2, h * 4 + 4), (255, 0, 255))
        pair.paste(comp.crop((x, y, x + w, y + h)).resize((w * 2, h * 2), Image.LANCZOS), (0, 0))
        pair.paste(shot.crop((x, y, x + w, y + h)).resize((w * 2, h * 2), Image.LANCZOS), (0, h * 2 + 4))
        pair.save(f"{a.out}-region-{i}.png")

    print(json.dumps(result))


if __name__ == "__main__":
    main()
