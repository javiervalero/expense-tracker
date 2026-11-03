# Remote-state bootstrap root. Applied once, by hand, with local state (Phase 7); everything else is stored in the
# bucket it creates. This file pins the tool and provider versions.
terraform {
  required_version = ">= 1.10.0, < 1.17.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "6.68.0"
    }
  }
}
