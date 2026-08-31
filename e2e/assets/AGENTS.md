# Test Assets

## Importing test images

```bash
convert /path/to/source.jpg -resize 640x480 -quality 1 e2e/assets/test_01.jpg
```

- Resize to a small dimension (e.g. 640x480) to keep file sizes minimal.
- Use `-quality 1` for maximum JPEG compression.
