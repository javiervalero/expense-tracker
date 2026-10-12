provider "aws" {
  region = var.aws_region

  # Refuses to run against any other account than the intended one.
  allowed_account_ids = [var.aws_account_id]

  default_tags {
    tags = {
      project    = "expense-tracker"
      env        = var.environment
      managed-by = "terraform"
    }
  }
}
