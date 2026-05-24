<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatPushNotificationService
{
    public function __construct(
        private readonly FcmService $fcm,
    ) {}

    public function notifyNewMessage(Message $message, Booking $booking): void
    {
        if (! $this->fcm->isConfigured()) {
            Log::warning('Chat push skipped: Firebase not configured on server (missing service-account JSON)');

            return;
        }

        $booking->loadMissing(['hall.owner', 'customer']);

        $recipient = $this->resolveRecipient($message->sender_id, $booking);
        if ($recipient === null) {
            Log::warning('Chat push skipped: no recipient', ['booking_id' => $booking->id]);

            return;
        }

        $senderLabel = $this->resolveSenderLabel($message->sender_id, $booking);
        $preview = Str::limit(trim($message->content), 160, '…');

        $title = $senderLabel;
        $body = $preview;
        $hallName = $booking->hall?->name ?? 'Venue';

        $sent = $this->fcm->sendToUser($recipient, $title, $body, [
            'type' => 'chat',
            'booking_id' => (string) $booking->id,
            'hall_name' => $hallName,
            'sender_name' => $senderLabel,
            'message_preview' => $preview,
        ]);

        Log::info('Chat push dispatched', [
            'booking_id' => $booking->id,
            'recipient_user_id' => $recipient->id,
            'devices_notified' => $sent,
        ]);
    }

    private function resolveRecipient(int $senderId, Booking $booking): ?User
    {
        if ((int) $booking->customer_id === $senderId) {
            return $booking->hall?->owner;
        }

        if ($booking->hall && (int) $booking->hall->owner_id === $senderId) {
            return $booking->customer;
        }

        Log::warning('Chat push: could not resolve recipient', [
            'booking_id' => $booking->id,
            'sender_id' => $senderId,
        ]);

        return null;
    }

    private function resolveSenderLabel(int $senderId, Booking $booking): string
    {
        if ((int) $booking->customer_id === $senderId) {
            return $booking->customer_name
                ?? $booking->customer?->name
                ?? 'Customer';
        }

        return $booking->hall?->name ?? 'Venue';
    }
}
