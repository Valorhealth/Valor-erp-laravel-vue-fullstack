import json
import os
from datetime import datetime, timezone
from pathlib import Path


class LocalBudgetStore:
    def __init__(self, root: Path):
        self.root = root.resolve()
        self.root.mkdir(parents=True, exist_ok=True)

    def _path(self, key: str) -> Path:
        path = (self.root / key).resolve()
        if self.root not in path.parents and path != self.root:
            raise ValueError("Invalid budget storage key")
        return path

    def list_queue_keys(self) -> list[str]:
        return [path.relative_to(self.root).as_posix() for path in self.root.glob("budget-queue/*/*.json")]

    def exists(self, key: str) -> bool:
        return self._path(key).is_file()

    def read_json(self, key: str) -> dict:
        return json.loads(self._path(key).read_text(encoding="utf-8"))

    def write_json(self, key: str, payload: dict) -> None:
        path = self._path(key)
        path.parent.mkdir(parents=True, exist_ok=True)
        payload = {**payload, "updated_at": datetime.now(timezone.utc).isoformat()}
        temporary = path.with_suffix(path.suffix + ".tmp")
        temporary.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
        temporary.replace(path)

    def download(self, key: str, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(self._path(key).read_bytes())

    def upload(self, source: Path, key: str) -> None:
        destination = self._path(key)
        destination.parent.mkdir(parents=True, exist_ok=True)
        temporary = destination.with_suffix(destination.suffix + ".tmp")
        temporary.write_bytes(source.read_bytes())
        temporary.replace(destination)

    def delete(self, key: str) -> None:
        self._path(key).unlink(missing_ok=True)


class S3BudgetStore:
    def __init__(self):
        import boto3

        self.bucket = required_env("SUPABASE_STORAGE_BUCKET")
        self.client = boto3.client(
            "s3",
            endpoint_url=required_env("SUPABASE_STORAGE_ENDPOINT"),
            aws_access_key_id=required_env("SUPABASE_STORAGE_ACCESS_KEY_ID"),
            aws_secret_access_key=required_env("SUPABASE_STORAGE_SECRET_ACCESS_KEY"),
            region_name=os.getenv("SUPABASE_STORAGE_REGION", "ap-southeast-1"),
        )

    def list_queue_keys(self) -> list[str]:
        keys: list[str] = []
        paginator = self.client.get_paginator("list_objects_v2")
        for page in paginator.paginate(Bucket=self.bucket, Prefix="budget-queue/"):
            keys.extend(
                item["Key"] for item in page.get("Contents", [])
                if item["Key"].endswith(".json")
            )
        return keys

    def exists(self, key: str) -> bool:
        from botocore.exceptions import ClientError

        try:
            self.client.head_object(Bucket=self.bucket, Key=key)
            return True
        except ClientError as exception:
            if exception.response.get("Error", {}).get("Code") in {"404", "NoSuchKey", "NotFound"}:
                return False
            raise

    def read_json(self, key: str) -> dict:
        response = self.client.get_object(Bucket=self.bucket, Key=key)
        return json.loads(response["Body"].read().decode("utf-8"))

    def write_json(self, key: str, payload: dict) -> None:
        payload = {**payload, "updated_at": datetime.now(timezone.utc).isoformat()}
        self.client.put_object(
            Bucket=self.bucket,
            Key=key,
            Body=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
            ContentType="application/json; charset=utf-8",
        )

    def download(self, key: str, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        self.client.download_file(self.bucket, key, str(destination))

    def upload(self, source: Path, key: str) -> None:
        self.client.upload_file(str(source), self.bucket, key)

    def delete(self, key: str) -> None:
        self.client.delete_object(Bucket=self.bucket, Key=key)


def required_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


def make_budget_store(local_root: Path):
    driver = os.getenv("BUDGET_STORAGE_DRIVER", "local").strip().lower()
    if driver in {"s3", "supabase"}:
        return S3BudgetStore()
    return LocalBudgetStore(local_root)
