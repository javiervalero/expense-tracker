# The DynamoDB table of the application (Phase 7 plan D6, D15). The key schema, the attribute definitions, the local
# secondary index and the TTL attribute are read from infra/dynamodb/table.json, the file the local stack and the
# contract tests use as well, so the cloud table cannot drift from the one the code was tested against.
# Billing is on-demand with a throughput ceiling (D15): the ceiling is the only limit on spend.

locals {
  definition = jsondecode(file("${path.module}/../../../dynamodb/table.json"))

  key_schema = { for k in local.definition.KeySchema : k.KeyType => k.AttributeName }

  lsis = local.definition.LocalSecondaryIndexes
}

resource "aws_dynamodb_table" "this" {
  name         = var.table_name
  billing_mode = local.definition.BillingMode

  hash_key  = local.key_schema["HASH"]
  range_key = local.key_schema["RANGE"]

  dynamic "attribute" {
    for_each = local.definition.AttributeDefinitions
    content {
      name = attribute.value.AttributeName
      type = attribute.value.AttributeType
    }
  }

  dynamic "local_secondary_index" {
    for_each = local.lsis
    content {
      name               = local_secondary_index.value.IndexName
      range_key          = [for k in local_secondary_index.value.KeySchema : k.AttributeName if k.KeyType == "RANGE"][0]
      projection_type    = local_secondary_index.value.Projection.ProjectionType
      non_key_attributes = try(local_secondary_index.value.Projection.NonKeyAttributes, null)
    }
  }

  ttl {
    attribute_name = local.definition.TimeToLiveAttribute
    enabled        = true
  }

  point_in_time_recovery {
    enabled = true
  }

  # Dev: Phase 8 tests destroy and recreate the environment.
  deletion_protection_enabled = false

  on_demand_throughput {
    max_read_request_units  = var.max_read_request_units
    max_write_request_units = var.max_write_request_units
  }
}
