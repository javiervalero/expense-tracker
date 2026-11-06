# The origin-verify secrets (D16, ADR 0012): CloudFront sends one of them in X-Origin-Verify, the API accepts both, so a
# rotation never has a moment where the live slot is unknown to the API. They live in the state in plain text (accepted,
# ADR 0012); every value is sensitive, so plan output shows `(sensitive value)`, and no output carries one (outputs.tf).

# Length 64 and no special characters: alphanumeric always satisfies ApiConfig::SLOT_PATTERN and cannot contain the comma
# that separates the slots in ORIGIN_VERIFY_SECRETS. Changing a rotation variable renews that slot only.
resource "random_password" "origin_verify_a" {
  length  = 64
  special = false

  keepers = {
    rotation = var.origin_verify_rotation_a
  }
}

resource "random_password" "origin_verify_b" {
  length  = 64
  special = false

  keepers = {
    rotation = var.origin_verify_rotation_b
  }
}

locals {
  # What the API accepts: both slots, in this order.
  origin_verify_secrets = "${random_password.origin_verify_a.result},${random_password.origin_verify_b.result}"

  # What CloudFront sends: the active slot.
  origin_verify_active_secret = var.origin_verify_active_slot == "a" ? random_password.origin_verify_a.result : random_password.origin_verify_b.result
}
