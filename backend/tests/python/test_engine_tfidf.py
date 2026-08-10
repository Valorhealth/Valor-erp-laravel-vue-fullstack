import os
import sys
import tempfile
import unittest
from pathlib import Path

import pandas as pd

os.environ["REDBOOK_EMBEDDING_BACKEND"] = "tfidf"
SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))

from engine_redbook import start_analysis_pipeline


class TfidfBudgetEngineTest(unittest.TestCase):
    def test_pipeline_exports_the_same_excel_contract_without_torch(self):
        with tempfile.TemporaryDirectory() as temporary:
            work_dir = Path(temporary)
            source = work_dir / "redbook.xlsx"
            result = work_dir / "result.xlsx"
            cache = work_dir / "vectors.cache"
            pd.DataFrame([
                {"agc_name": "Hospital A", "item_name": "เครื่องวัดทางการแพทย์", "p_total_budget": 10000},
                {"agc_name": "Hospital B", "item_name": "อุปกรณ์ห้องผ่าตัด", "p_total_budget": 20000},
                {"agc_name": "Hospital C", "item_name": "โต๊ะสำนักงาน", "p_total_budget": 5000},
            ]).to_excel(source, index=False)

            summary = start_analysis_pipeline(
                source,
                result,
                product_desc="เครื่องวัดทางการแพทย์",
                score_threshold=0.0,
                max_ai_rows=2,
                use_ollama=False,
                embedding_cache_path=cache,
            )

            self.assertTrue(result.is_file())
            self.assertTrue(cache.is_file())
            self.assertEqual(3, summary["rows_in_file"])
            self.assertGreater(summary["rows_exported"], 0)


if __name__ == "__main__":
    unittest.main()
