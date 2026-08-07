<?php

declare(strict_types=1);

use ExpertSystems\Kudosity\Laravel\Notifications\KudosityMessage;

describe('KudosityMessage', function () {
    describe('construction', function () {
        it('creates message with content', function () {
            $message = new KudosityMessage('Hello World');
            expect($message->getContent())->toBe('Hello World');
        });

        it('creates message with empty content by default', function () {
            $message = new KudosityMessage;
            expect($message->getContent())->toBe('');
        });
    });

    describe('fluent builder', function () {
        it('sets content via fluent method', function () {
            $message = (new KudosityMessage)
                ->content('Hello World');

            expect($message->getContent())->toBe('Hello World');
        });

        it('sets recipient via to() method', function () {
            $message = (new KudosityMessage('Test'))
                ->to('61491570006');

            expect($message->getTo())->toBe('61491570006');
        });

        it('sets list ID via toList() method', function () {
            $message = (new KudosityMessage('Test'))
                ->toList(12345);

            expect($message->getListId())->toBe(12345);
        });

        it('sets sender via from() method', function () {
            $message = (new KudosityMessage('Test'))
                ->from('MyBrand');

            expect($message->getFrom())->toBe('MyBrand');
        });

        it('enables number formatting via formatNumbers() method', function () {
            $message = (new KudosityMessage('Test'))
                ->formatNumbers();

            expect($message->getFormatNumbers())->toBeTrue();
        });

        it('disables number formatting when passed false', function () {
            $message = (new KudosityMessage('Test'))
                ->formatNumbers(false);

            expect($message->getFormatNumbers())->toBeFalse();
        });

        it('sets scheduled time via sendAt() method', function () {
            $message = (new KudosityMessage('Test'))
                ->sendAt('2025-12-06 10:00:00');

            expect($message->getSendAt())->toBe('2025-12-06 10:00:00');
        });

        it('sets validity via validity() method', function () {
            $message = (new KudosityMessage('Test'))
                ->validity(60);

            expect($message->getValidity())->toBe(60);
        });

        it('sets country code via countryCode() method', function () {
            $message = (new KudosityMessage('Test'))
                ->countryCode('AU');

            expect($message->getCountryCode())->toBe('AU');
        });

        it('sets replies to email via repliesToEmail() method', function () {
            $message = (new KudosityMessage('Test'))
                ->repliesToEmail('test@example.com');

            expect($message->getRepliesToEmail())->toBe('test@example.com');
        });

        it('sets tracked link URL via trackedLinkUrl() method', function () {
            $message = (new KudosityMessage('Test'))
                ->trackedLinkUrl('https://example.com/track');

            expect($message->getTrackedLinkUrl())->toBe('https://example.com/track');
        });

        it('sets DLR callback via dlrCallback() method', function () {
            $message = (new KudosityMessage('Test'))
                ->dlrCallback('https://example.com/dlr');

            expect($message->getDlrCallback())->toBe('https://example.com/dlr');
        });

        it('sets reply callback via replyCallback() method', function () {
            $message = (new KudosityMessage('Test'))
                ->replyCallback('https://example.com/reply');

            expect($message->getReplyCallback())->toBe('https://example.com/reply');
        });

        it('sets link hits callback via linkHitsCallback() method', function () {
            $message = (new KudosityMessage('Test'))
                ->linkHitsCallback('https://example.com/hits');

            expect($message->getLinkHitsCallback())->toBe('https://example.com/hits');
        });

        it('chains all methods together', function () {
            $message = (new KudosityMessage)
                ->content('Hello World')
                ->to('61491570006')
                ->from('MyBrand')
                ->sendAt('2025-12-06 10:00:00')
                ->validity(60)
                ->countryCode('AU')
                ->repliesToEmail('test@example.com')
                ->trackedLinkUrl('https://example.com/track')
                ->dlrCallback('https://example.com/dlr')
                ->replyCallback('https://example.com/reply')
                ->linkHitsCallback('https://example.com/hits');

            expect($message->getContent())->toBe('Hello World');
            expect($message->getTo())->toBe('61491570006');
            expect($message->getFrom())->toBe('MyBrand');
            expect($message->getSendAt())->toBe('2025-12-06 10:00:00');
            expect($message->getValidity())->toBe(60);
            expect($message->getCountryCode())->toBe('AU');
            expect($message->getRepliesToEmail())->toBe('test@example.com');
            expect($message->getTrackedLinkUrl())->toBe('https://example.com/track');
            expect($message->getDlrCallback())->toBe('https://example.com/dlr');
            expect($message->getReplyCallback())->toBe('https://example.com/reply');
            expect($message->getLinkHitsCallback())->toBe('https://example.com/hits');
        });

        it('returns self for fluent chaining', function () {
            $message = new KudosityMessage;

            expect($message->content('Test'))->toBe($message);
            expect($message->to('61491570006'))->toBe($message);
            expect($message->from('MyBrand'))->toBe($message);
            expect($message->sendAt('2025-12-06 10:00:00'))->toBe($message);
            expect($message->validity(60))->toBe($message);
            expect($message->countryCode('AU'))->toBe($message);
            expect($message->repliesToEmail('test@example.com'))->toBe($message);
            expect($message->trackedLinkUrl('https://example.com/track'))->toBe($message);
            expect($message->dlrCallback('https://example.com/dlr'))->toBe($message);
            expect($message->replyCallback('https://example.com/reply'))->toBe($message);
            expect($message->linkHitsCallback('https://example.com/hits'))->toBe($message);
        });
    });

    describe('default values', function () {
        it('returns null for unset to', function () {
            $message = new KudosityMessage('Test');
            expect($message->getTo())->toBeNull();
        });

        it('returns null for unset listId', function () {
            $message = new KudosityMessage('Test');
            expect($message->getListId())->toBeNull();
        });

        it('returns false for formatNumbers by default', function () {
            $message = new KudosityMessage('Test');
            expect($message->getFormatNumbers())->toBeFalse();
        });

        it('returns null for unset from', function () {
            $message = new KudosityMessage('Test');
            expect($message->getFrom())->toBeNull();
        });

        it('returns null for unset sendAt', function () {
            $message = new KudosityMessage('Test');
            expect($message->getSendAt())->toBeNull();
        });

        it('returns null for unset validity', function () {
            $message = new KudosityMessage('Test');
            expect($message->getValidity())->toBeNull();
        });

        it('returns null for unset countryCode', function () {
            $message = new KudosityMessage('Test');
            expect($message->getCountryCode())->toBeNull();
        });

        it('returns null for unset repliesToEmail', function () {
            $message = new KudosityMessage('Test');
            expect($message->getRepliesToEmail())->toBeNull();
        });

        it('returns null for unset trackedLinkUrl', function () {
            $message = new KudosityMessage('Test');
            expect($message->getTrackedLinkUrl())->toBeNull();
        });

        it('returns null for unset dlrCallback', function () {
            $message = new KudosityMessage('Test');
            expect($message->getDlrCallback())->toBeNull();
        });

        it('returns null for unset replyCallback', function () {
            $message = new KudosityMessage('Test');
            expect($message->getReplyCallback())->toBeNull();
        });

        it('returns null for unset linkHitsCallback', function () {
            $message = new KudosityMessage('Test');
            expect($message->getLinkHitsCallback())->toBeNull();
        });
    });
});
