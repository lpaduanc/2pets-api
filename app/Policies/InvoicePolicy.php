<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Matriz de autorização de faturamento — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §5. Front desk (qualquer
 * membro ativo da organização) COBRA uma fatura já emitida, mas só quem atendeu (autor) ou
 * quem administra a clínica (dono da organização) decide o que está sendo cobrado antes de
 * emitir — mesmo espírito de separar "concluir agenda" de "decidir conteúdo clínico".
 */
class InvoicePolicy
{
    /**
     * Invariante 2 do contrato: rascunho é tão invisível ao tutor/colega comum quanto
     * `MedicalRecord.status = draft` — só autor ou dono da organização veem um rascunho.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        if ($this->isAuthorOrOwner($user, $invoice)) {
            return true;
        }

        if ($invoice->isDraft()) {
            return false;
        }

        if ($invoice->client_id === $user->id) {
            return true;
        }

        return $invoice->organization_id !== null && $user->isActiveMemberOfOrganization($invoice->organization_id);
    }

    /**
     * `destroy` — descarte definitivo de um RASCUNHO manual, só autor ou dono da
     * organização. Fatura de atendimento (`appointment_id` presente) nunca passa por
     * `draft` (§13.5), então esta ability nunca autoriza apagá-la — o caminho para
     * descartar uma fatura de atendimento indesejada é `cancel()` (mantém o registro,
     * auditável, com motivo), nunca um `DELETE` que a removeria da tabela sem rastro do
     * porquê. `editWhilePending()` cobre a edição de itens; esta cobre só a remoção do
     * rascunho manual que ainda nem chegou a ser uma cobrança real.
     */
    public function editDraft(User $user, Invoice $invoice): bool
    {
        return $invoice->isDraft() && $this->isAuthorOrOwner($user, $invoice);
    }

    /**
     * `update` (edição de itens) — contrato §13.5/§13.8 invariante 13 (revisão do dono do
     * produto, 2026-09-16): fatura DE ATENDIMENTO (`appointment_id` presente) nasce
     * `pending` direto — nunca passa por `draft` — e os itens só congelam no PAGAMENTO,
     * não na emissão. Por isso ela é editável enquanto `pending`, não só enquanto `draft`.
     * Fatura MANUAL (`appointment_id` nulo) continua sob a invariante 8, intacta para
     * esse caso: editável só em `draft`, travada assim que sai dele — corrigir depois
     * disso é `cancel()` + nova fatura, nunca editar. Em qualquer um dos dois casos,
     * `paid`/`cancelled`/`refunded` nunca é editável, e só autor ou dono da organização
     * editam — um colega comum da mesma organização pode `receivePayment()`, mas não
     * decide o que está sendo cobrado (mesma separação já valia para `draft`, seção 5 do
     * contrato, e se estende sem mudança para a janela `pending` de atendimento).
     */
    public function editWhilePending(User $user, Invoice $invoice): bool
    {
        if (! $this->isAuthorOrOwner($user, $invoice)) {
            return false;
        }

        if ($invoice->appointment_id !== null) {
            return $invoice->isPending();
        }

        return $invoice->isDraft();
    }

    /**
     * `issue`/`cancel` — autor ou dono da organização. A validade da transição em si
     * (`draft → pending`, `draft|pending → cancelled`) é responsabilidade de
     * `InvoiceLifecycleService` (422 se inválida), não desta policy (403 é sobre QUEM, não
     * sobre EM QUE ESTADO).
     */
    public function manage(User $user, Invoice $invoice): bool
    {
        return $this->isAuthorOrOwner($user, $invoice);
    }

    /**
     * `mark-as-paid` — autor, dono da organização, OU qualquer membro ativo da mesma
     * organização (front desk recebe no balcão sem ter atendido).
     */
    public function receivePayment(User $user, Invoice $invoice): bool
    {
        if ($this->isAuthorOrOwner($user, $invoice)) {
            return true;
        }

        return $invoice->organization_id !== null && $user->isActiveMemberOfOrganization($invoice->organization_id);
    }

    private function isAuthorOrOwner(User $user, Invoice $invoice): bool
    {
        if ($invoice->professional_id === $user->id) {
            return true;
        }

        return $invoice->organization_id !== null && $user->ownsOrganization($invoice->organization_id);
    }
}
