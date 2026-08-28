import sys
import tempfile
import unittest
from pathlib import Path

from openpyxl import Workbook

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))

from scan_purchase_order import excel_text, normalize


class PurchaseOrderScanTest(unittest.TestCase):
    def test_normalize_handles_comma_formatted_amounts_and_invalid_values(self):
        result = normalize({
            "po_number": "PO-100",
            "confidence": "0.92",
            "items": [{"source_name": "Fibroscan", "quantity": "2", "unit_price": "25,000.00", "discount": "invalid"}],
        })
        self.assertEqual("PO-100", result["po_number"])
        self.assertEqual(2, result["items"][0]["quantity"])
        self.assertEqual(25000.0, result["items"][0]["unit_price"])
        self.assertEqual(0.0, result["items"][0]["discount"])

    def test_excel_text_extracts_cells_for_ai_without_formulas(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "po.xlsx"
            workbook = Workbook()
            sheet = workbook.active
            sheet.title = "PO"
            sheet.append(["PO No", "PO-200"])
            sheet.append(["Product", "BRE Shower Sheet", 3, 1500])
            workbook.save(path)
            text = excel_text(path)
        self.assertIn("[Sheet: PO]", text)
        self.assertIn("PO-200", text)
        self.assertIn("BRE Shower Sheet", text)


if __name__ == "__main__":
    unittest.main()
