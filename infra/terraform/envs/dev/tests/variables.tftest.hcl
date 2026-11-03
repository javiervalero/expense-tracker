# Offline tests of the input validations. The AWS provider is mocked, so nothing here can reach an account
# (rule 3.1 of the Phase 7 plan; scripts/check-tftest-mocks.sh enforces the mock in every test file).
mock_provider "aws" {}

variables {
  aws_region                = "us-east-1"
  aws_account_id            = "123456789012" # the AWS documentation example account
  environment               = "dev"
  origin_verify_active_slot = "a"
}

run "accepts_valid_values" {
  command = plan
}

run "accepts_slot_b" {
  command = plan
  variables {
    origin_verify_active_slot = "b"
  }
}

run "rejects_bad_region" {
  command = plan
  variables {
    aws_region = "Mars"
  }
  expect_failures = [var.aws_region]
}

run "rejects_bad_account_id" {
  command = plan
  variables {
    aws_account_id = "12345"
  }
  expect_failures = [var.aws_account_id]
}

run "rejects_account_id_with_letters" {
  command = plan
  variables {
    aws_account_id = "12345678901a"
  }
  expect_failures = [var.aws_account_id]
}

run "rejects_bad_environment" {
  command = plan
  variables {
    environment = "prod"
  }
  expect_failures = [var.environment]
}

run "rejects_bad_active_slot" {
  command = plan
  variables {
    origin_verify_active_slot = "c"
  }
  expect_failures = [var.origin_verify_active_slot]
}
