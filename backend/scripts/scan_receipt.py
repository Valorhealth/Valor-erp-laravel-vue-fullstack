import argparse
import json
import mimetypes
import tomllib
from pathlib import Path

from google import genai
from google.genai import types


PROMPT = """
คุณคือระบบ OCR สำหรับอ่านใบเสร็จ ใบเสนอราคา หรือเอกสารการซื้อขายภาษาไทย
สกัดข้อมูลแล้วตอบเป็น JSON เท่านั้น ห้ามมี markdown หรือคำอธิบายอื่น โครงสร้าง:
{
  "buyer_name": "ชื่อบริษัทหรือผู้ซื้อ",
  "tax_id": "เลขผู้เสียภาษี",
  "main_category": "ยี่ห้อหรือรหัสสินค้า",
  "sub_category": "ชื่อสินค้าหรือรหัสสินค้า",
  "unit_type": "หน่วยสินค้า",
  "unit_price": 0,
  "quantity": 1,
  "province": "จังหวัด",
  "district": "อำเภอหรือเขต",
  "sub_district": "ตำบลหรือแขวง",
  "zipcode": "รหัสไปรษณีย์",
  "address_note": "บ้านเลขที่ ซอย ถนน และรายละเอียดที่อยู่",
  "bill_note": "หมายเหตุเอกสาร"
}
ข้อมูลที่หาไม่พบให้ใช้ค่าว่าง หรือตัวเลข 0
"""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("image_path")
    args = parser.parse_args()
    backend_root = Path(__file__).resolve().parents[1]
    with (backend_root / ".secrets" / "integrations.toml").open("rb") as file:
        secrets = tomllib.load(file)
    client = genai.Client(api_key=secrets["GEMINI_API_KEY"])
    image_path = Path(args.image_path)
    mime_type = mimetypes.guess_type(image_path.name)[0] or "image/jpeg"
    image_part = types.Part.from_bytes(data=image_path.read_bytes(), mime_type=mime_type)
    response = client.models.generate_content(
        model=secrets.get("GEMINI_MODEL", "gemini-3.5-flash"),
        contents=[PROMPT, image_part],
        config=types.GenerateContentConfig(response_mime_type="application/json"),
    )
    clean = response.text.replace("```json", "").replace("```", "").strip()
    print(json.dumps(json.loads(clean), ensure_ascii=False))


if __name__ == "__main__":
    main()
