import argparse
import json
import sys
import tempfile
import time
from pathlib import Path

from budget_storage import make_budget_store


def find_next_job(store) -> tuple[str, str, str] | None:
    candidates = []
    for queue_key in store.list_queue_keys():
        try:
            queued_job = store.read_json(queue_key)
            job_key = queued_job["job_key"]
            status_key = queued_job["status_key"]
            status = store.read_json(status_key)
        except Exception:
            continue
        if status.get("status") == "queued":
            if store.exists(job_key):
                candidates.append((status.get("updated_at", ""), queue_key, job_key, status_key))
        elif status.get("status") in {"completed", "failed", "cancelled"}:
            store.delete(queue_key)

    if not candidates:
        return None

    _, queue_key, job_key, status_key = sorted(candidates)[0]
    current = store.read_json(status_key)
    attempts = int(current.get("attempts", 0))
    if attempts >= 3:
        store.write_json(status_key, {
            "status": "failed",
            "progress": int(current.get("progress", 0)),
            "attempts": attempts,
            "message": "Worker ถูกขัดจังหวะหลายครั้งเกินกำหนด",
            "error": "กรุณากดเริ่มวิเคราะห์ใหม่ หรือตรวจสอบหน่วยความจำของ Server",
        })
        store.delete(queue_key)
        return None
    store.write_json(status_key, {
        "status": "processing",
        "progress": max(2, int(current.get("progress", 1))),
        "attempts": attempts + 1,
        "message": "Worker รับงานแล้ว กำลังเตรียมวิเคราะห์",
    })
    return queue_key, job_key, status_key


def resume_interrupted_jobs(store) -> None:
    for queue_key in store.list_queue_keys():
        try:
            queued_job = store.read_json(queue_key)
            status_key = queued_job["status_key"]
            status = store.read_json(status_key)
            if status.get("status") == "processing":
                store.write_json(status_key, {
                    "status": "queued",
                    "progress": max(1, int(status.get("progress", 1))),
                    "attempts": int(status.get("attempts", 1)),
                    "message": "ระบบกลับมาออนไลน์แล้ว กำลังนำงานเข้าคิวเพื่อทำต่อ",
                })
        except Exception:
            continue


def run_stored_job(store, job_key: str, status_key: str, run_job) -> None:
    remote_job = store.read_json(job_key)

    with tempfile.TemporaryDirectory(prefix="valor-budget-") as temporary:
        work_dir = Path(temporary)
        input_path = work_dir / "input.xlsx"
        output_path = work_dir / "result.xlsx"
        status_path = work_dir / "status.json"
        config_path = work_dir / "job.json"
        cache_path = work_dir / "embedding-cache.pt"

        store.download(remote_job["input_key"], input_path)
        if remote_job.get("embedding_cache_key") and store.exists(remote_job["embedding_cache_key"]):
            store.download(remote_job["embedding_cache_key"], cache_path)

        local_job = {
            **remote_job,
            "input_path": str(input_path),
            "output_path": str(output_path),
            "status_path": str(status_path),
            "embedding_cache_path": str(cache_path),
        }
        config_path.write_text(json.dumps(local_job, ensure_ascii=False), encoding="utf-8")
        status_path.write_text(json.dumps(store.read_json(status_key), ensure_ascii=False), encoding="utf-8")

        def publish_status(payload: dict) -> None:
            current = store.read_json(status_key)
            store.write_json(status_key, {
                **payload,
                "attempts": int(current.get("attempts", 1)),
            })

        def publish_artifacts(config: dict) -> None:
            store.upload(Path(config["output_path"]), remote_job["output_key"])
            if cache_path.is_file() and remote_job.get("embedding_cache_key"):
                store.upload(cache_path, remote_job["embedding_cache_key"])

        run_job(
            config_path,
            status_reader=lambda: store.read_json(status_key),
            status_writer=publish_status,
            before_complete=publish_artifacts,
        )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("queue_root")
    parser.add_argument("--poll-seconds", type=float, default=1.0)
    args = parser.parse_args()

    scripts_dir = Path(__file__).resolve().parent
    sys.path.insert(0, str(scripts_dir))
    from run_budget_job import run_job

    store = make_budget_store(Path(args.queue_root))
    resume_interrupted_jobs(store)

    while True:
        next_job = find_next_job(store)
        if next_job is None:
            time.sleep(max(args.poll_seconds, 0.25))
            continue

        try:
            queue_key, job_key, status_key = next_job
            run_stored_job(store, job_key, status_key, run_job)
            store.delete(queue_key)
        except Exception as exception:
            try:
                current = store.read_json(status_key)
                if current.get("status") not in {"failed", "cancelled"}:
                    store.write_json(status_key, {
                        "status": "failed",
                        "progress": int(current.get("progress", 0)),
                        "attempts": int(current.get("attempts", 1)),
                        "message": "วิเคราะห์ไม่สำเร็จ",
                        "error": str(exception)[:2000],
                    })
                store.delete(queue_key)
            except Exception:
                pass


if __name__ == "__main__":
    main()
