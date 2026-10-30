<?php

namespace Omnibank\Bridge\Action;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Bridge\Api;
use Omnibank\Bridge\Mapper;
use Omnibank\Exception\ProviderException;
use Omnibank\Model\Consent;
use Omnibank\Model\Notification;
use Omnibank\Request\Notify;
use Omnibank\Request\Request;

/**
 * A webhook, its signature checked (BridgeApi-Signature, see Api): the
 * item.* events read as what they mean for the consent - the item's status
 * one the user must act on (402, 429, 430, 1010, 1100) needs renewal, a
 * deleted item is revoked, any other status is active. Other events (a test
 * event, a user's) come back with consent NONE - they say nothing of it -
 * to read from $raw. The connection is the item (content.item_id).
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $this->api->verify($request->body, $request->header(Api::SIGNATURE_HEADER));

        $event = json_decode($request->body, true);
        if (!\is_array($event) || !\is_string($event['type'] ?? null)) {
            throw new ProviderException('bridge', 'The notification is not a Bridge event.');
        }
        $content = \is_array($event['content'] ?? null) ? $event['content'] : [];
        $type = $event['type'];
        $consent = match (true) {
            !str_starts_with($type, 'item.') => Consent::none(),
            str_ends_with($type, '.deleted') && !str_contains($type, '.account.') => Consent::revoked(),
            default => Mapper::consent($content['status'] ?? null, isset($content['status_code_info']) ? (string) $content['status_code_info'] : null),
        };

        $request->setResult(new Notification(
            type: $type,
            connectionId: isset($content['item_id']) ? (string) $content['item_id'] : null,
            consent: $consent,
            raw: $event,
            state: str_starts_with($type, 'item.') ? Mapper::connectionState($content['status'] ?? null) : null,
        ));
    }
}
