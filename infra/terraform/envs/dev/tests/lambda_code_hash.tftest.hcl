# Offline tests of the code-hash postcondition of modules/lambda-function (Phase 7 security review L3): after apply, the
# hash Lambda reports for the stored code (code_sha256) must equal the base64 sha256 the module computed from
# package_sha256, so the zip that is uploaded is the zip that was hashed.
# The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in
# every test file). Under the mock code_sha256 is a random string, so each run pins it with override_resource. This is a
# file of its own because a file-level override of the same address would win over the run-level ones used here.
mock_provider "aws" {}

variables {
  function_name     = "expense-tracker-dev-worker"
  handler           = "entrypoints/lambda/worker.php"
  memory_size       = 1024
  timeout           = 30
  aws_region        = "us-east-1"
  package_path      = "../../../../build/lambda/backend.zip"
  package_sha256    = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" # sha256 of the empty input
  php_layer_version = 20
  environment_variables = {
    BREF_RUNTIME = "function"
  }
  policy_json = "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"dynamodb:GetItem\"],\"Resource\":[\"arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev\"]}]}"
}

override_resource {
  target = aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

run "a_matching_code_hash_passes" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  override_resource {
    target = aws_lambda_function.this
    values = {
      code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
    }
  }

  assert {
    condition     = aws_lambda_function.this.code_sha256 == aws_lambda_function.this.source_code_hash
    error_message = "The reported code hash must equal the hash the module sent."
  }
}

# Runs of the same module share one state in `terraform test`, and an override only applies when the resource is created.
# Each run here therefore has a state of its own, or it would keep the code hash of the run before it.
run "the_hash_conversion_is_right_for_a_second_vector" {
  command   = apply
  state_key = "second_vector"

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    package_sha256 = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad" # sha256("abc")
  }

  override_resource {
    target = aws_lambda_function.this
    values = {
      code_sha256 = "ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0="
    }
  }

  assert {
    condition     = aws_lambda_function.this.source_code_hash == "ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0="
    error_message = "source_code_hash must be the base64 form of the hex sha256 of \"abc\"."
  }
}

run "a_reported_code_hash_that_differs_fails_the_postcondition" {
  command   = apply
  state_key = "mismatch"

  module {
    source = "../../modules/lambda-function"
  }

  # The hash of "abc" is reported for a package that was hashed as the empty input.
  override_resource {
    target = aws_lambda_function.this
    values = {
      code_sha256 = "ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0="
    }
  }

  expect_failures = [aws_lambda_function.this]
}
