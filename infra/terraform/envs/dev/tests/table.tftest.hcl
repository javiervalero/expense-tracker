# Offline tests of modules/table (D6 and D15 of the Phase 7 plan). The AWS provider is mocked, so nothing here can reach
# an account (scripts/check-tftest-mocks.sh enforces the mock in every test file). Every run block loads the module
# directly (`module { source = ... }`). `command = apply`: under the mock, computed values are only known after apply.
# The table definition is infra/dynamodb/table.json (the file the local stack and the contract tests also use), so these
# tests pin what Terraform derives from it.
mock_provider "aws" {}

variables {
  table_name              = "expense-tracker-dev"
  max_read_request_units  = 50
  max_write_request_units = 25
}

run "name_and_billing_mode" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = aws_dynamodb_table.this.name == "expense-tracker-dev"
    error_message = "The table must take the given name."
  }

  assert {
    condition     = aws_dynamodb_table.this.billing_mode == "PAY_PER_REQUEST"
    error_message = "The table must be on-demand (PAY_PER_REQUEST)."
  }
}

run "keys_come_from_table_json" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = aws_dynamodb_table.this.hash_key == "PK"
    error_message = "The hash key must be PK."
  }

  assert {
    condition     = aws_dynamodb_table.this.range_key == "SK"
    error_message = "The range key must be SK."
  }

  assert {
    condition     = length(aws_dynamodb_table.this.attribute) == 3
    error_message = "There must be exactly three attribute definitions (PK, SK, LSI1SK)."
  }

  assert {
    condition = { for a in aws_dynamodb_table.this.attribute : a.name => a.type } == {
      PK     = "S"
      SK     = "S"
      LSI1SK = "S"
    }
    error_message = "PK, SK and LSI1SK must all be strings."
  }
}

run "lsi1_projects_all_attributes" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = length(aws_dynamodb_table.this.local_secondary_index) == 1
    error_message = "There must be exactly one local secondary index."
  }

  assert {
    condition     = one(aws_dynamodb_table.this.local_secondary_index).name == "LSI1"
    error_message = "The index must be named LSI1."
  }

  assert {
    condition     = one(aws_dynamodb_table.this.local_secondary_index).range_key == "LSI1SK"
    error_message = "LSI1 must be sorted by LSI1SK."
  }

  assert {
    condition     = one(aws_dynamodb_table.this.local_secondary_index).projection_type == "ALL"
    error_message = "LSI1 must project ALL attributes."
  }
}

run "ttl_uses_expires_at" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = one(aws_dynamodb_table.this.ttl).attribute_name == "expiresAt"
    error_message = "The TTL attribute must be expiresAt."
  }

  assert {
    condition     = one(aws_dynamodb_table.this.ttl).enabled == true
    error_message = "TTL must be enabled."
  }
}

run "point_in_time_recovery_is_on" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = one(aws_dynamodb_table.this.point_in_time_recovery).enabled == true
    error_message = "Point-in-time recovery must be enabled."
  }
}

run "deletion_protection_is_off_for_dev" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = aws_dynamodb_table.this.deletion_protection_enabled == false
    error_message = "Deletion protection must be off in dev (Phase 8 tests destroy)."
  }
}

run "ceiling_is_set_from_the_variables" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = one(aws_dynamodb_table.this.on_demand_throughput).max_read_request_units == 50
    error_message = "max_read_request_units must be the given ceiling."
  }

  assert {
    condition     = one(aws_dynamodb_table.this.on_demand_throughput).max_write_request_units == 25
    error_message = "max_write_request_units must be the given ceiling."
  }
}

run "outputs_expose_name_and_arn" {
  command = apply

  module {
    source = "../../modules/table"
  }

  assert {
    condition     = output.table_name == "expense-tracker-dev"
    error_message = "table_name must be the table name."
  }

  assert {
    condition     = output.table_arn == aws_dynamodb_table.this.arn
    error_message = "table_arn must be the table ARN."
  }
}

run "rejects_a_ceiling_below_one" {
  command = plan

  module {
    source = "../../modules/table"
  }

  variables {
    max_read_request_units = 0
  }

  expect_failures = [var.max_read_request_units]
}

run "rejects_a_write_ceiling_below_one" {
  command = plan

  module {
    source = "../../modules/table"
  }

  variables {
    max_write_request_units = 0
  }

  expect_failures = [var.max_write_request_units]
}

run "rejects_a_ceiling_of_minus_one" {
  command = plan

  module {
    source = "../../modules/table"
  }

  variables {
    max_read_request_units = -1
  }

  expect_failures = [var.max_read_request_units]
}
