terraform {
  # 1.10 introduced S3-native state locking (`use_lockfile`); the upper bound matches the pinned image (1.16.x).
  required_version = ">= 1.10.0, < 1.17.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "6.68.0"
    }
  }

  # Partial configuration: only the non-secret settings are committed. Phase 7 supplies bucket, key and region with
  # `terraform init -backend-config=...`. `make tf-check` runs `init -backend=false`, so the backend is never contacted.
  backend "s3" {
    use_lockfile = true
    encrypt      = true
  }
}
