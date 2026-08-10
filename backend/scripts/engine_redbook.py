import os
import re
from functools import lru_cache

import numpy as np
import pandas as pd
import requests


DEFAULT_PRODUCT_DESC = (
    "เครื่องมือแพทย์ อุปกรณ์ทางการแพทย์ วัสดุสิ้นเปลืองทางการแพทย์ "
    "และระบบสนับสนุนงานโรงพยาบาล"
)
DEFAULT_OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "scb10x/llama3.1-typhoon2-8b-instruct:latest")
OLLAMA_URL = os.getenv("OLLAMA_URL", "http://localhost:11434/api/generate")
OLLAMA_TAGS_URL = os.getenv("OLLAMA_TAGS_URL", "http://localhost:11434/api/tags")
DEFAULT_OLLAMA_TIMEOUT = int(os.getenv("OLLAMA_TIMEOUT", "180"))
MODEL_NAME = os.getenv("REDBOOK_EMBEDDING_MODEL", "paraphrase-multilingual-MiniLM-L12-v2")
EMBEDDING_BACKEND = os.getenv("REDBOOK_EMBEDDING_BACKEND", "transformer").strip().lower()

REDBOOK_OUTPUT_COLUMNS = [
    "agc_name",
    "group_budget",
    "plan_name",
    "output_name",
    "act_name",
    "objc_8_name",
    "cap_ncap",
    "item_name",
    "p_total_budget",
]

REDBOOK_COLUMN_ALIASES = {
    "agc_anme": "agc_name",
    "agc_name": "agc_name",
    "group_budget": "group_budget",
    "plan_name": "plan_name",
    "output_name": "output_name",
    "act_name": "act_name",
    "objc_8_name": "objc_8_name",
    "obj_name": "objc_8_name",
    "cap_ncap": "cap_ncap",
    "item_name": "item_name",
    "p_total_bud": "p_total_budget",
    "p_total_budget": "p_total_budget",
    "p_budget": "p_total_budget",
}

CONTEXT_COLUMN_CANDIDATES = [
    "item_name",
    "objc_8_name",
    "cap_ncap",
    "act_name",
    "output_name",
    "รายการ",
    "ชื่อรายการ",
    "รายการงบประมาณ",
    "project_name",
    "โครงการ",
    "plan_name",
    "แผนงาน",
    "obj_name",
    "หมวดรายจ่าย",
    "ministry",
    "กระทรวง",
    "department",
    "หน่วยงาน",
]

INVESTMENT_KEYWORDS = (
    "ครุภัณฑ์|ลงทุน|ก่อสร้าง|พัฒนา|ระบบ|เครื่อง|อุปกรณ์|เวชภัณฑ์|"
    "การแพทย์|โรงพยาบาล"
)


def report_progress(progress_callback, percent, message):
    if progress_callback:
        progress_callback(percent, message)


def parse_product_queries(product_desc):
    products = []
    current_lines = []

    for line in str(product_desc or "").splitlines():
        raw_line = line.strip()
        if not raw_line:
            continue

        numbered_match = re.match(r"^\s*\d+[\).:-]?\s*(.+)$", raw_line)
        if numbered_match:
            if current_lines:
                products.append(" ".join(current_lines).strip())
            current_lines = [numbered_match.group(1).strip()]
        elif current_lines:
            current_lines.append(raw_line)
        else:
            current_lines = [re.sub(r"^\s*[-*•]\s*", "", raw_line).strip()]

    if current_lines:
        products.append(" ".join(current_lines).strip())

    if not products and str(product_desc or "").strip():
        products.append(str(product_desc).strip())

    return [product for product in products if product]


def make_excel_sheet_name(value, used_names):
    sheet_name = re.sub(r"[\[\]\:\*\?\/\\]", " ", str(value or "Product")).strip()
    sheet_name = re.sub(r"\s+", " ", sheet_name) or "Product"
    sheet_name = sheet_name[:31]

    base_name = sheet_name
    counter = 2
    while sheet_name in used_names:
        suffix = f" {counter}"
        sheet_name = f"{base_name[:31 - len(suffix)]}{suffix}"
        counter += 1

    used_names.add(sheet_name)
    return sheet_name


def budget_to_number(series):
    return pd.to_numeric(series.astype(str).str.replace(",", "", regex=False), errors="coerce").fillna(0)


@lru_cache(maxsize=1)
def get_vector_tools():
    try:
        from sentence_transformers import SentenceTransformer, util
    except ImportError as e:
        raise RuntimeError("ยังไม่ได้ติดตั้ง sentence-transformers กรุณารัน: pip install sentence-transformers") from e

    return SentenceTransformer(MODEL_NAME), util


def encode_contexts_with_progress(vector_model, contexts, progress_callback, batch_size=256):
    import torch

    batches = []
    total = max(len(contexts), 1)
    for start in range(0, len(contexts), batch_size):
        end = min(start + batch_size, len(contexts))
        batches.append(
            vector_model.encode(
                contexts[start:end],
                convert_to_tensor=True,
                show_progress_bar=False,
            )
        )
        percent = 30 + int((end / total) * 30)
        report_progress(
            progress_callback,
            min(percent, 60),
            f"สร้าง Vector embeddings แล้ว {end:,}/{len(contexts):,} แถว",
        )

    if not batches:
        raise ValueError("ไม่พบข้อมูลสำหรับสร้าง Vector embeddings")

    return torch.cat(batches, dim=0)


def normalize_columns(df):
    df = df.copy()
    df.columns = [normalize_column_name(col) for col in df.columns]
    return df


def normalize_column_name(column_name):
    raw_name = str(column_name).strip()
    lower_name = raw_name.lower().replace(" ", "_")

    for alias, canonical in REDBOOK_COLUMN_ALIASES.items():
        if alias in lower_name:
            return canonical

    return raw_name


def count_redbook_columns(columns):
    normalized = [normalize_column_name(col) for col in columns]
    return len(set(normalized) & set(REDBOOK_OUTPUT_COLUMNS))


def make_unique_columns(columns):
    seen = {}
    unique_cols = []

    for col in columns:
        col = normalize_column_name(col)
        if col not in seen:
            seen[col] = 0
            unique_cols.append(col)
        else:
            seen[col] += 1
            unique_cols.append(f"{col}_{seen[col]}")

    return unique_cols


def read_redbook_excel(input_path):
    best_df = None
    best_score = -1

    with pd.ExcelFile(input_path) as excel:
        for sheet_name in excel.sheet_names:
            candidate = read_redbook_sheet(input_path, sheet_name)
            redbook_cols = count_redbook_columns(candidate.columns)
            row_count = len(candidate.dropna(how="all"))
            is_data_dictionary = {"LIST", "FIELD", "DESCRIPTION"}.issubset(set(candidate.columns))
            score = (redbook_cols * 1000) + min(row_count, 999)
            if is_data_dictionary:
                score -= 5000

            if score > best_score:
                best_score = score
                best_df = candidate

    return best_df if best_df is not None else normalize_columns(pd.read_excel(input_path))


def read_redbook_sheet(input_path, sheet_name):
    df_default = normalize_columns(pd.read_excel(input_path, sheet_name=sheet_name))
    if count_redbook_columns(df_default.columns) >= 3:
        return df_default.dropna(how="all").reset_index(drop=True)

    raw = pd.read_excel(input_path, sheet_name=sheet_name, header=None)
    best_row = None
    best_score = 0
    scan_rows = min(40, len(raw))

    for row_idx in range(scan_rows):
        row_values = raw.iloc[row_idx].fillna("").astype(str).tolist()
        score = count_redbook_columns(row_values)
        if score > best_score:
            best_score = score
            best_row = row_idx

    if best_row is None or best_score < 3:
        return df_default.dropna(how="all").reset_index(drop=True)

    columns = make_unique_columns(raw.iloc[best_row].fillna("").astype(str).tolist())
    df = raw.iloc[best_row + 1 :].copy()
    df.columns = columns
    return df.dropna(how="all").reset_index(drop=True)


def pick_existing_columns(df, candidates):
    return [col for col in candidates if col in df.columns]


def build_context(df):
    context_cols = pick_existing_columns(df, CONTEXT_COLUMN_CANDIDATES)
    if not context_cols:
        context_cols = df.columns[: min(6, len(df.columns))].tolist()

    context = df[context_cols].fillna("").astype(str).agg(" ".join, axis=1).str.strip()
    return context, context_cols


def filter_investment_rows(df):
    obj_cols = [
        col
        for col in ["objc_8_name", "cap_ncap", "act_name", "output_name", "หมวดรายจ่าย", "รายการ", "item_name"]
        if col in df.columns
    ]
    if not obj_cols:
        return df

    mask = pd.Series(False, index=df.index)
    for col in obj_cols:
        mask = mask | df[col].astype(str).str.contains(INVESTMENT_KEYWORDS, na=False, regex=True)

    filtered = df[mask].copy()
    return filtered if not filtered.empty else df


def compact_text(value, max_chars=900):
    text = str(value or "").strip()
    return text if len(text) <= max_chars else text[:max_chars] + "..."


def ask_typhoon_local(product_desc, budget_item, model_name=DEFAULT_OLLAMA_MODEL, timeout=DEFAULT_OLLAMA_TIMEOUT):
    product_desc = compact_text(product_desc, 500)
    budget_item = compact_text(budget_item, 900)
    prompt = f"""
คุณคือผู้เชี่ยวชาญด้านจัดซื้อจัดจ้างภาครัฐและการจับคู่งบประมาณกับสินค้าเอกชน

สินค้า:
{product_desc}

รายการงบ:
{budget_item}

ตอบภาษาไทยสั้นมากในรูปแบบ:
เหมาะสม: ได้/อาจได้/ไม่ควร
เหตุผล: 1 ประโยค
แนะนำ: 1 ประโยค
"""
    try:
        response = requests.post(
            OLLAMA_URL,
            json={
                "model": model_name,
                "prompt": prompt,
                "stream": False,
                "keep_alive": "10m",
                "options": {
                    "temperature": 0.2,
                    "num_predict": 160,
                },
            },
            timeout=timeout,
        )
        if response.status_code == 404:
            detail = response.text.strip()
            try:
                detail = response.json().get("error", detail)
            except ValueError:
                pass
            return (
                f"Ollama หาโมเดล `{model_name}` ไม่เจอ หรือ endpoint ไม่ตรง: {detail} "
                f"กรุณาเช็กด้วยคำสั่ง `ollama list` แล้วใช้ชื่อโมเดลให้ตรง"
            )
        response.raise_for_status()
        return response.json().get("response", "Ollama ไม่ส่งคำตอบกลับมา")
    except requests.Timeout:
        return (
            f"Ollama ตอบช้าเกิน {timeout} วินาที จึงข้ามการวิเคราะห์เหตุผลแถวนี้ "
            f"แนะนำให้ลด Top N, เพิ่ม timeout, หรือเปิด Ollama/โมเดลให้พร้อมก่อนรัน"
        )
    except requests.RequestException as e:
        return f"Ollama ยังไม่พร้อมใช้งานหรือเรียกโมเดลไม่ได้: {e}"


def list_ollama_models():
    try:
        response = requests.get(OLLAMA_TAGS_URL, timeout=5)
        response.raise_for_status()
        models = response.json().get("models", [])
        return [model.get("name") for model in models if model.get("name")]
    except requests.RequestException:
        return []


def build_missing_model_message(model_name, available_models):
    if available_models:
        return (
            f"Ollama ไม่มีโมเดล `{model_name}` ในเครื่อง "
            f"โมเดลที่มีตอนนี้: {', '.join(available_models)} "
            f"กรุณาเลือกชื่อโมเดลให้ตรงกับรายการนี้"
        )
    return (
        f"Ollama ไม่มีโมเดล `{model_name}` หรือยังไม่ได้เปิด Ollama "
        f"กรุณารัน `ollama list` เพื่อตรวจสอบ หรือ `ollama pull <model-name>` เพื่อติดตั้งโมเดล"
    )


def score_budget_items(df, product_desc, progress_callback=None):
    report_progress(progress_callback, 25, "กำลังโหลดโมเดล Vector Search")
    vector_model, util = get_vector_tools()

    contexts = df["context"].fillna("").astype(str).tolist()
    if not contexts:
        df["match_score"] = []
        return df

    report_progress(progress_callback, 35, "กำลังสร้าง embedding ของสินค้า")
    product_embed = vector_model.encode(product_desc, convert_to_tensor=True)
    report_progress(progress_callback, 45, f"กำลังสร้าง embedding ของข้อมูลงบประมาณ {len(contexts):,} แถว")
    gov_embeds = vector_model.encode(contexts, convert_to_tensor=True)
    cosine_scores = util.cos_sim(product_embed, gov_embeds)[0]

    df = df.copy()
    df["match_score"] = [round(float(score), 4) for score in cosine_scores]
    report_progress(progress_callback, 65, "คำนวณคะแนนความเหมือนเสร็จแล้ว")
    return df


def score_budget_items_from_embeddings(df, product_desc, vector_model, util, gov_embeds):
    if util is None:
        product_embed = vector_model.transform([product_desc])
        cosine_scores = (gov_embeds @ product_embed.T).toarray().ravel()
        scored = df.copy()
        scored["match_score"] = [round(float(score), 4) for score in cosine_scores]
        return scored

    product_embed = vector_model.encode(product_desc, convert_to_tensor=True)
    cosine_scores = util.cos_sim(product_embed, gov_embeds)[0]

    scored = df.copy()
    scored["match_score"] = [round(float(score), 4) for score in cosine_scores]
    return scored


def prepare_result_sheet(df_filtered, context_cols, product_query, ai_reasons):
    df_filtered = df_filtered.copy()
    df_filtered["AI_Analysis_Reason"] = ai_reasons
    df_filtered["matched_context_columns"] = ", ".join(context_cols)
    df_filtered["product_query"] = product_query

    analysis_cols = ["match_score", "AI_Analysis_Reason", "product_query", "matched_context_columns", "context"]
    priority_cols = [col for col in REDBOOK_OUTPUT_COLUMNS if col in df_filtered.columns]
    remaining_cols = [col for col in df_filtered.columns if col not in priority_cols + analysis_cols]
    ordered_cols = priority_cols + remaining_cols + [col for col in analysis_cols if col in df_filtered.columns]
    return df_filtered[ordered_cols]


def build_product_summary(product_name, sheet_name, result_df):
    total_budget = 0
    if "p_total_budget" in result_df.columns:
        total_budget = budget_to_number(result_df["p_total_budget"]).sum()

    org_count = result_df["agc_name"].nunique() if "agc_name" in result_df.columns else 0
    top_score = result_df["match_score"].max() if "match_score" in result_df.columns and not result_df.empty else 0
    top_orgs = ""
    if "agc_name" in result_df.columns:
        top_orgs = ", ".join(result_df["agc_name"].dropna().astype(str).head(5).unique())

    top_groups = ""
    if "group_budget" in result_df.columns:
        top_groups = ", ".join(result_df["group_budget"].dropna().astype(str).head(5).unique())

    return {
        "product_query": product_name,
        "sheet_name": sheet_name,
        "matched_rows": len(result_df),
        "top_match_score": round(float(top_score), 4) if pd.notna(top_score) else 0,
        "total_budget": total_budget,
        "organization_count": org_count,
        "top_organizations": top_orgs,
        "top_budget_groups": top_groups,
    }


def build_group_summary(result_df, group_col):
    if result_df.empty or group_col not in result_df.columns:
        return pd.DataFrame()

    summary_df = result_df.copy()
    summary_df["_budget_number"] = (
        budget_to_number(summary_df["p_total_budget"]) if "p_total_budget" in summary_df.columns else 0
    )
    grouped = (
        summary_df.groupby(group_col, dropna=False)
        .agg(
            matched_rows=("match_score", "size"),
            total_budget=("_budget_number", "sum"),
            top_match_score=("match_score", "max"),
            product_count=("product_query", "nunique"),
        )
        .reset_index()
        .sort_values(["total_budget", "matched_rows"], ascending=False)
    )
    return grouped


def start_analysis_pipeline(
    input_path,
    output_path,
    product_desc=DEFAULT_PRODUCT_DESC,
    score_threshold=0.35,
    max_ai_rows=8,
    ollama_model=DEFAULT_OLLAMA_MODEL,
    use_ollama=False,
    ollama_timeout=DEFAULT_OLLAMA_TIMEOUT,
    progress_callback=None,
    embedding_cache_path=None,
):
    product_queries = parse_product_queries(product_desc)
    if not product_queries:
        raise ValueError("กรุณาระบุสินค้า/บริการอย่างน้อย 1 รายการ")

    report_progress(progress_callback, 5, "กำลังอ่านไฟล์ Excel และเลือกชีตงบประมาณ")
    df_raw = read_redbook_excel(input_path)
    if df_raw.empty:
        raise ValueError("ไฟล์ Excel ไม่มีข้อมูลให้วิเคราะห์")

    report_progress(progress_callback, 15, f"อ่านข้อมูลได้ {len(df_raw):,} แถว")
    df_gov = filter_investment_rows(normalize_columns(df_raw))
    report_progress(progress_callback, 18, f"กรองเหลือรายการงบที่เกี่ยวข้อง {len(df_gov):,} แถว")
    report_progress(progress_callback, 20, "กำลังสร้างข้อความ context สำหรับจับคู่")
    df_gov["context"], context_cols = build_context(df_gov)
    df_gov = df_gov[df_gov["context"].str.len() > 0].copy()

    if df_gov.empty:
        raise ValueError("ไม่พบข้อความในไฟล์ Excel ที่สามารถนำมาวิเคราะห์ได้")

    context_codes, unique_contexts = pd.factorize(df_gov["context"].fillna("").astype(str), sort=False)
    unique_contexts = unique_contexts.tolist()
    report_progress(
        progress_callback,
        30,
        f"เตรียม Vector embeddings {len(unique_contexts):,} context จากข้อมูล {len(df_gov):,} แถว",
    )

    if EMBEDDING_BACKEND == "tfidf":
        import pickle
        from sklearn.feature_extraction.text import TfidfVectorizer

        vector_model = None
        unique_embeds = None
        if embedding_cache_path and os.path.isfile(embedding_cache_path):
            try:
                with open(embedding_cache_path, "rb") as cache_file:
                    cached = pickle.load(cache_file)
                if cached.get("context_count") == len(unique_contexts):
                    vector_model = cached["vectorizer"]
                    unique_embeds = cached["embeddings"]
                    report_progress(progress_callback, 60, "ใช้ TF-IDF Vector cache ของไฟล์นี้สำเร็จ")
            except (OSError, ValueError, KeyError, pickle.UnpicklingError):
                vector_model = None
                unique_embeds = None

        if unique_embeds is None:
            report_progress(progress_callback, 35, "กำลังสร้าง TF-IDF Vector Search แบบประหยัดหน่วยความจำ")
            vector_model = TfidfVectorizer(analyzer="char_wb", ngram_range=(2, 5), dtype=np.float32)
            unique_embeds = vector_model.fit_transform(unique_contexts)
            report_progress(progress_callback, 60, f"สร้าง TF-IDF vectors แล้ว {len(unique_contexts):,} context")
            if embedding_cache_path:
                os.makedirs(os.path.dirname(embedding_cache_path), exist_ok=True)
                temporary_cache = f"{embedding_cache_path}.tmp"
                with open(temporary_cache, "wb") as cache_file:
                    pickle.dump({
                        "context_count": len(unique_contexts),
                        "vectorizer": vector_model,
                        "embeddings": unique_embeds,
                    }, cache_file, protocol=pickle.HIGHEST_PROTOCOL)
                os.replace(temporary_cache, embedding_cache_path)

        util = None
        gov_embeds = unique_embeds[context_codes]
    else:
        report_progress(progress_callback, 25, "กำลังโหลดโมเดล Vector Search")
        vector_model, util = get_vector_tools()
        import torch

        unique_embeds = None
        if embedding_cache_path and os.path.isfile(embedding_cache_path):
            cached = torch.load(embedding_cache_path, map_location=vector_model.device, weights_only=True)
            if len(cached) == len(unique_contexts):
                unique_embeds = cached
                report_progress(progress_callback, 60, "ใช้ Vector cache ของไฟล์นี้สำเร็จ")

        if unique_embeds is None:
            unique_embeds = encode_contexts_with_progress(vector_model, unique_contexts, progress_callback)
            if embedding_cache_path:
                os.makedirs(os.path.dirname(embedding_cache_path), exist_ok=True)
                temporary_cache = f"{embedding_cache_path}.tmp"
                torch.save(unique_embeds.cpu(), temporary_cache)
                os.replace(temporary_cache, embedding_cache_path)

        gov_embeds = unique_embeds[torch.as_tensor(context_codes, dtype=torch.long, device=unique_embeds.device)]

    missing_model_message = None
    if use_ollama:
        report_progress(progress_callback, 62, "กำลังตรวจสอบโมเดล Ollama")
        available_models = list_ollama_models()
        if ollama_model not in available_models:
            missing_model_message = build_missing_model_message(ollama_model, available_models)

    used_sheet_names = {"Summary"}
    product_sheets = []
    summary_rows = []
    total_products = len(product_queries)

    for product_index, product_query in enumerate(product_queries, start=1):
        base_progress = 65 + int(((product_index - 1) / total_products) * 25)
        report_progress(progress_callback, base_progress, f"กำลังจับคู่งบกับสินค้า {product_index}/{total_products}")

        df_scored = score_budget_items_from_embeddings(df_gov, product_query, vector_model, util, gov_embeds)
        df_scored = df_scored.sort_values("match_score", ascending=False).copy()
        df_filtered = df_scored[df_scored["match_score"] >= float(score_threshold)].copy()

        if df_filtered.empty:
            df_filtered = df_scored.head(max_ai_rows).copy()

        if not use_ollama:
            ai_reasons = ["ยังไม่ได้เปิดการวิเคราะห์เหตุผลด้วย Ollama"] * len(df_filtered)
        elif missing_model_message:
            ai_reasons = [missing_model_message] * len(df_filtered)
        else:
            ai_reasons = ["ไม่ได้ส่งเข้า Ollama เพราะอยู่นอก Top N"] * len(df_filtered)
            rows_for_ai = list(df_filtered.head(max_ai_rows).iterrows())
            ai_total = max(len(rows_for_ai), 1)
            for reason_idx, (_, row) in enumerate(rows_for_ai):
                item_progress = base_progress + int(((reason_idx + 1) / ai_total) * max(1, 25 // total_products))
                report_progress(
                    progress_callback,
                    min(item_progress, 93),
                    f"กำลังให้ Ollama วิเคราะห์ {product_index}/{total_products} รายการที่ {reason_idx + 1}/{ai_total}",
                )
                ai_reasons[reason_idx] = ask_typhoon_local(
                    product_query,
                    row["context"],
                    ollama_model,
                    timeout=ollama_timeout,
                )

        result_sheet = prepare_result_sheet(df_filtered, context_cols, product_query, ai_reasons)
        sheet_name = make_excel_sheet_name(product_query, used_sheet_names)
        product_sheets.append((sheet_name, result_sheet))
        summary_rows.append(build_product_summary(product_query, sheet_name, result_sheet))

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    report_progress(progress_callback, 96, "กำลังบันทึก Excel แบบหลาย sheet")
    combined_results = (
        pd.concat([result_sheet for _, result_sheet in product_sheets], ignore_index=True)
        if product_sheets
        else pd.DataFrame()
    )
    org_summary = build_group_summary(combined_results, "agc_name")
    budget_group_summary = build_group_summary(combined_results, "group_budget")

    with pd.ExcelWriter(output_path, engine="openpyxl") as writer:
        summary_df = pd.DataFrame(summary_rows)
        summary_df.to_excel(writer, sheet_name="Summary", index=False)
        next_row = len(summary_df) + 3
        if not org_summary.empty:
            pd.DataFrame([{"summary": "Budget by organization"}]).to_excel(
                writer, sheet_name="Summary", startrow=next_row, index=False
            )
            org_summary.to_excel(writer, sheet_name="Summary", startrow=next_row + 2, index=False)
            next_row += len(org_summary) + 5
        if not budget_group_summary.empty:
            pd.DataFrame([{"summary": "Budget by budget group"}]).to_excel(
                writer, sheet_name="Summary", startrow=next_row, index=False
            )
            budget_group_summary.to_excel(writer, sheet_name="Summary", startrow=next_row + 2, index=False)

        for sheet_name, result_sheet in product_sheets:
            result_sheet.to_excel(writer, sheet_name=sheet_name, index=False)

    rows_exported = sum(len(result_sheet) for _, result_sheet in product_sheets)
    report_progress(progress_callback, 100, "วิเคราะห์เสร็จสมบูรณ์")

    return {
        "rows_in_file": len(df_raw),
        "rows_after_filter": len(df_gov),
        "rows_exported": rows_exported,
        "context_columns": context_cols,
        "output_path": output_path,
        "product_count": len(product_queries),
        "products": product_queries,
        "sheet_names": ["Summary", *[sheet_name for sheet_name, _ in product_sheets]],
    }
