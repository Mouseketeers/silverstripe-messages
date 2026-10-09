<?php

namespace Mouseketeers\Messages\Channel;

use Mouseketeers\Messages\Message;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Config;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailChannel implements MessageChannelInterface
{
    public function code(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function canSend(Message $message): bool
    {
        $recipient = $message->Member();
        return $recipient->exists()
            && !empty($recipient->Email)
            && filter_var($recipient->Email, FILTER_VALIDATE_EMAIL);
    }

    public function send(Message $message): bool
    {
        $originalThemeEnabled = (bool) Config::inst()->get(SSViewer::class, 'theme_enabled');
        $originalThemes = SSViewer::get_themes() ?: [];

        Config::modify()->set(SSViewer::class, 'theme_enabled', true);

        try {
            $message->extend('beforeSendMessageEmail');

            $fromEmail = Message::config()->get('default_from_email');
            if (!$fromEmail) {
                $siteConfig = SiteConfig::current_site_config();
                $fromEmail = $siteConfig->DefaultFromEmail ?? null;
            }
            if (!$fromEmail) {
                $fromEmail = Email::config()->get('admin_email');
            }

            $fromEmailAddress = $this->parseEmailAddress($fromEmail);
            $fromEmailName = $this->parseEmailName($fromEmail);

            $recipient = $message->Member();
            if (!$fromEmailAddress || !$recipient->Email) {
                return false;
            }
            if (!filter_var($fromEmailAddress, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Sender email address is invalid: ' . $fromEmailAddress);
            }
            if (!filter_var($recipient->Email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Recipient email address is invalid: ' . $recipient->Email);
            }

            $email = Email::create()
                ->setFrom($fromEmailAddress, $fromEmailName)
                ->setTo($recipient->Email)
                ->setSubject($message->Title)
                ->setHTMLTemplate('Email/MessageEmail');

            $email->setData([
                'FirstName' => $recipient->FirstName,
                'Surname'   => $recipient->Surname,
                'Body'      => $message->dbObject('Body'),
                'Image'     => $message->Image(),
                'Video'     => $message->Video(),
            ]);

            try {
                $email->send();
            } catch (TransportExceptionInterface $e) {
                throw new RuntimeException('Mailer failed to send the email: ' . $e->getMessage(), 0, $e);
            }
            return true;
        } finally {
            SSViewer::set_themes($originalThemes);
            Config::modify()->set(SSViewer::class, 'theme_enabled', $originalThemeEnabled);
        }
    }

    private function parseEmailAddress(?string $email): ?string
    {
        if (!$email) {
            return null;
        }
        if (preg_match('/.*<([^>]+)>/', $email, $matches)) {
            return trim($matches[1]);
        }
        return trim($email);
    }

    private function parseEmailName(?string $email): ?string
    {
        if (!$email) {
            return null;
        }
        if (preg_match('/^(.+)<[^>]+>$/', $email, $matches)) {
            $name = trim($matches[1]);
            $name = trim($name, '"\' ');
            return $name ?: null;
        }
        return null;
    }
}
