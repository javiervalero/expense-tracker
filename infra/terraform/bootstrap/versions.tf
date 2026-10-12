# Remote-state bootstrap root. Applied once, by hand, with local state (Phase 7); everything else is stored in the
# bucket it creates. Phase 0 only pins the tool and provider versions, so that `make tf-check` can validate it.
terraform {
  required_version = ">= 1.10.0, < 1.17.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "6.68.0"
    }
  }
}
