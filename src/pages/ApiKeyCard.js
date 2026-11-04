/**
 * WordPress dependencies
 */
import {
  Button,
  TextControl,
  __experimentalConfirmDialog as ConfirmDialog,
  __experimentalHStack as HStack,
} from "@wordpress/components";
import { useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { seen, unseen } from "@wordpress/icons";

/**
 * Internal dependencies
 */
import Section from "../components/Section";
import SettingRow from "../components/SettingRow";
import { EnvBadge } from "./ConnectionCard";

/**
 * Write-only API key: the saved key is shown only by its prefix; a new key
 * typed here is sent on Save and never read back.
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function ApiKeyCard({ store }) {
  const [visible, setVisible] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const { settings } = store;
  const typed = settings.api_key || "";

  if (settings.key_locked) {
    return (
      <Section
        className="is-accent"
        title={__("API key", "trustorder-for-woo")}
        badge={<EnvBadge env={settings.env} />}
      >
        <SettingRow
          title={__("Key from the configuration", "trustorder-for-woo")}
          help={__(
            "The key is set by the TRUSTORDER_API_KEY constant (wp-config.php or .env), so it cannot be changed here.",
            "trustorder-for-woo",
          )}
        >
          <span className="to-keyprefix">
            {settings.has_key
              ? `${settings.key_prefix}…`
              : __("Not a valid key", "trustorder-for-woo")}
          </span>
        </SettingRow>
      </Section>
    );
  }

  return (
    <Section
      className="is-accent"
      title={__("API key", "trustorder-for-woo")}
      badge={<EnvBadge env={settings.env} />}
      description={__(
        "A key starting with to_test_ works in test mode: checks and reports go to a separate test database.",
        "trustorder-for-woo",
      )}
    >
      {settings.has_key && (
        <SettingRow
          title={__("Saved key", "trustorder-for-woo")}
          help={__(
            "For safety only the beginning of the key is shown.",
            "trustorder-for-woo",
          )}
        >
          <HStack justify="flex-start" spacing={3} wrap>
            <span className="to-keyprefix">{`${settings.key_prefix}…`}</span>
            <Button
              __next40pxDefaultSize
              variant="tertiary"
              isDestructive
              disabled={store.isSaving}
              accessibleWhenDisabled
              onClick={() => setConfirming(true)}
            >
              {__("Remove key", "trustorder-for-woo")}
            </Button>
          </HStack>
        </SettingRow>
      )}
      <SettingRow
        title={
          settings.has_key
            ? __("Replace the key", "trustorder-for-woo")
            : __("Your API key", "trustorder-for-woo")
        }
        help={__(
          "Paste the key you got from upTools, then save.",
          "trustorder-for-woo",
        )}
      >
        <HStack alignment="bottom" spacing={2} className="to-keyrow">
          <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
            label={__("API key", "trustorder-for-woo")}
            hideLabelFromVision
            type={visible ? "text" : "password"}
            placeholder="to_live_…"
            autoComplete="off"
            spellCheck={false}
            value={typed}
            onChange={(value) => store.update({ api_key: value })}
          />
          <Button
            __next40pxDefaultSize
            variant="secondary"
            icon={visible ? unseen : seen}
            label={
              visible
                ? __("Hide key", "trustorder-for-woo")
                : __("Show key", "trustorder-for-woo")
            }
            isPressed={visible}
            onClick={() => setVisible(!visible)}
          />
        </HStack>
      </SettingRow>
      <ConfirmDialog
        isOpen={confirming}
        confirmButtonText={__("Remove key", "trustorder-for-woo")}
        cancelButtonText={__("Cancel", "trustorder-for-woo")}
        onConfirm={() => {
          setConfirming(false);
          store.removeKey();
        }}
        onCancel={() => setConfirming(false)}
      >
        {__(
          "Remove the saved API key? Until you add a key again, no buyer is checked and no order outcome is reported.",
          "trustorder-for-woo",
        )}
      </ConfirmDialog>
    </Section>
  );
}
