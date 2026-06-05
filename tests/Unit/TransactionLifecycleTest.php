<?php

namespace Tests\Unit;

use App\Support\Transaction\TransactionLifecycle;
use App\Support\Transaction\TransactionStatus;
use Tests\TestCase;

class TransactionLifecycleTest extends TestCase
{
    public function test_draft_is_valid_transaction_status(): void
    {
        $this->assertTrue(TransactionLifecycle::isValidStatus(TransactionStatus::DRAFT));
    }

    public function test_approved_is_valid_transaction_status(): void
    {
        $this->assertTrue(TransactionLifecycle::isValidStatus(TransactionStatus::APPROVED));
    }

    public function test_posted_is_valid_transaction_status(): void
    {
        $this->assertTrue(TransactionLifecycle::isValidStatus(TransactionStatus::POSTED));
    }

    public function test_void_is_valid_transaction_status(): void
    {
        $this->assertTrue(TransactionLifecycle::isValidStatus(TransactionStatus::VOID));
    }

    public function test_obsolete_is_not_valid_main_transaction_status(): void
    {
        $this->assertFalse(TransactionLifecycle::isValidStatus(TransactionStatus::OBSOLETE));
    }

    public function test_obsolete_is_valid_effect_status(): void
    {
        $this->assertTrue(TransactionLifecycle::isValidEffectStatus(TransactionStatus::OBSOLETE));
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->assertFalse(TransactionLifecycle::isValidStatus('invalid'));
        $this->assertFalse(TransactionLifecycle::isValidEffectStatus('invalid'));
    }

    public function test_void_is_not_visible(): void
    {
        $this->assertFalse(TransactionLifecycle::isVisible(TransactionStatus::VOID));
    }

    public function test_posted_is_visible(): void
    {
        $this->assertTrue(TransactionLifecycle::isVisible(TransactionStatus::POSTED));
    }

    public function test_draft_is_editable(): void
    {
        $this->assertTrue(TransactionLifecycle::isEditableStatus(TransactionStatus::DRAFT));
    }

    public function test_approved_is_editable(): void
    {
        $this->assertTrue(TransactionLifecycle::isEditableStatus(TransactionStatus::APPROVED));
    }

    public function test_posted_is_editable(): void
    {
        $this->assertTrue(TransactionLifecycle::isEditableStatus(TransactionStatus::POSTED));
    }

    public function test_void_is_not_editable(): void
    {
        $this->assertFalse(TransactionLifecycle::isEditableStatus(TransactionStatus::VOID));
    }

    public function test_draft_is_voidable(): void
    {
        $this->assertTrue(TransactionLifecycle::isVoidableStatus(TransactionStatus::DRAFT));
    }

    public function test_approved_is_voidable(): void
    {
        $this->assertTrue(TransactionLifecycle::isVoidableStatus(TransactionStatus::APPROVED));
    }

    public function test_posted_is_voidable(): void
    {
        $this->assertTrue(TransactionLifecycle::isVoidableStatus(TransactionStatus::POSTED));
    }

    public function test_void_is_not_voidable(): void
    {
        $this->assertFalse(TransactionLifecycle::isVoidableStatus(TransactionStatus::VOID));
    }

    public function test_void_is_terminal(): void
    {
        $this->assertTrue(TransactionLifecycle::isTerminal(TransactionStatus::VOID));
    }

    public function test_posted_journal_is_reportable_when_not_obsolete(): void
    {
        $this->assertTrue(TransactionLifecycle::isReportableJournalStatus(TransactionStatus::POSTED, false));
    }

    public function test_posted_journal_is_not_reportable_when_obsolete(): void
    {
        $this->assertFalse(TransactionLifecycle::isReportableJournalStatus(TransactionStatus::POSTED, true));
    }

    public function test_void_journal_is_not_reportable(): void
    {
        $this->assertFalse(TransactionLifecycle::isReportableJournalStatus(TransactionStatus::VOID, false));
    }

    public function test_obsolete_effect_is_not_reportable(): void
    {
        $this->assertFalse(TransactionLifecycle::isReportableJournalStatus(TransactionStatus::OBSOLETE, true));
    }
}
