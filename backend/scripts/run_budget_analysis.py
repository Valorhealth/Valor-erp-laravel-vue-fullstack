import argparse
import json
import sys
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("input_path")
    parser.add_argument("output_path")
    parser.add_argument("product_desc")
    parser.add_argument("score_threshold", type=float)
    parser.add_argument("max_ai_rows", type=int)
    parser.add_argument("ollama_model")
    parser.add_argument("use_ollama", choices=("0", "1"))
    parser.add_argument("ollama_timeout", type=int)
    args = parser.parse_args()

    scripts_dir = Path(__file__).resolve().parent
    sys.path.insert(0, str(scripts_dir))
    from engine_redbook import start_analysis_pipeline

    summary = start_analysis_pipeline(
        args.input_path,
        args.output_path,
        product_desc=args.product_desc,
        score_threshold=args.score_threshold,
        max_ai_rows=args.max_ai_rows,
        ollama_model=args.ollama_model,
        use_ollama=args.use_ollama == "1",
        ollama_timeout=args.ollama_timeout,
    )
    summary["output_path"] = Path(args.output_path).name
    print(json.dumps(summary, ensure_ascii=False))


if __name__ == "__main__":
    main()
