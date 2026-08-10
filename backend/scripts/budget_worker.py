import argparse
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path


def read_status(path: Path) -> dict:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}


def write_status(path: Path, payload: dict) -> None:
    payload["updated_at"] = datetime.now(timezone.utc).isoformat()
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    temporary.replace(path)


def find_next_job(root: Path) -> Path | None:
    candidates = []
    for status_path in root.glob("*/*/status.json"):
        status = read_status(status_path)
        if status.get("status") == "queued":
            candidates.append((status_path.stat().st_mtime, status_path.parent / "job.json", status_path))

    for _, config_path, status_path in sorted(candidates):
        if config_path.is_file():
            write_status(status_path, {
                "status": "processing",
                "progress": max(2, int(read_status(status_path).get("progress", 1))),
                "message": "Worker รับงานแล้ว กำลังเตรียมวิเคราะห์",
            })
            return config_path
    return None


def cancel_interrupted_jobs(root: Path) -> None:
    for status_path in root.glob("*/*/status.json"):
        status = read_status(status_path)
        if status.get("status") == "processing":
            write_status(status_path, {
                "status": "failed",
                "progress": int(status.get("progress", 0)),
                "message": "งานหยุดเพราะมีการปิดหรือเปิดระบบใหม่",
                "error": "งานเดิมถูกยกเลิกแล้ว กรุณาเลือกไฟล์และกดเริ่มวิเคราะห์ใหม่",
            })


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("queue_root")
    parser.add_argument("--poll-seconds", type=float, default=1.0)
    args = parser.parse_args()

    queue_root = Path(args.queue_root).resolve()
    queue_root.mkdir(parents=True, exist_ok=True)
    scripts_dir = Path(__file__).resolve().parent
    sys.path.insert(0, str(scripts_dir))
    from run_budget_job import run_job

    cancel_interrupted_jobs(queue_root)

    while True:
        config_path = find_next_job(queue_root)
        if config_path is None:
            time.sleep(max(args.poll_seconds, 0.25))
            continue

        try:
            run_job(config_path)
        except Exception:
            # run_job records the full error and failed status for the UI.
            pass


if __name__ == "__main__":
    main()
