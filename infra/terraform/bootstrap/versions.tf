# Remote-state bootstrap root. Applied by hand with local state; everything else is stored in the bucket it creates.
# Its state stays local (terraform.tfstate next to this root), is git-ignored, and the owner must back it up outside
# the repo: it is the only record that the state bucket is managed. It is not migrated into the bucket (ADR 0014).
# This file pins the tool and provider versions.
terraform {
  required_version = ">= 1.10.0, < 1.17.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "6.68.0"
    }
  }
}
