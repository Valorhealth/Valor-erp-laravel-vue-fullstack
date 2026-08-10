import json
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))

from budget_storage import LocalBudgetStore
from budget_worker import find_next_job, run_stored_job


class BudgetWorkerTest(unittest.TestCase):
    def test_local_queue_publishes_result_and_completed_status(self):
        with tempfile.TemporaryDirectory() as temporary:
            store = LocalBudgetStore(Path(temporary))
            prefix = "budget/7/analysis-id"
            input_key = f"{prefix}/input.xlsx"
            output_key = f"{prefix}/result.xlsx"
            status_key = f"{prefix}/status.json"
            job_key = f"{prefix}/job.json"
            queue_key = "budget-queue/7/analysis-id.json"
            source = Path(temporary) / "source.xlsx"
            source.write_bytes(b"input")
            store.upload(source, input_key)
            store.write_json(status_key, {"status": "queued", "progress": 1})
            store.write_json(job_key, {
                "input_key": input_key,
                "output_key": output_key,
                "status_key": status_key,
                "embedding_cache_key": "budget-cache/input.pt",
                "product_desc": "medical device",
                "score_threshold": 0.2,
                "max_ai_rows": 8,
                "ollama_model": "unused",
                "use_ollama": False,
                "ollama_timeout": 180,
            })
            store.write_json(queue_key, {"job_key": job_key, "status_key": status_key})

            next_job = find_next_job(store)
            self.assertEqual((queue_key, job_key, status_key), next_job)

            def fake_runner(config_path, status_reader, status_writer, before_complete):
                config = json.loads(config_path.read_text(encoding="utf-8"))
                self.assertEqual(b"input", Path(config["input_path"]).read_bytes())
                Path(config["output_path"]).write_bytes(b"result")
                Path(config["embedding_cache_path"]).write_bytes(b"cache")
                before_complete(config)
                status_writer({"status": "completed", "progress": 100})

            run_stored_job(store, job_key, status_key, fake_runner)

            self.assertEqual("completed", store.read_json(status_key)["status"])
            result = Path(temporary) / "downloaded-result.xlsx"
            store.download(output_key, result)
            self.assertEqual(b"result", result.read_bytes())
            self.assertTrue(store.exists("budget-cache/input.pt"))


if __name__ == "__main__":
    unittest.main()
