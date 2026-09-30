"""Package the plugin's runtime files for WordPress's Upload Plugin screen."""

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


root = Path(__file__).resolve().parents[1]
files = (
    "wp-ai-fragments.php",
    "experiments/native-admin/native-admin.php",
    "experiments/native-admin/dist/view.html",
)

for name in files:
    if not (root / name).is_file():
        raise SystemExit(f"Missing runtime file: {name}. Run npm run build first.")

output = root / "dist" / "wp-ai-fragments.zip"
output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(output, "w", compression=ZIP_DEFLATED) as archive:
    for name in files:
        archive.write(root / name, arcname=f"wp-ai-fragments/{name}")

print(f"Plugin ZIP: {output}")
