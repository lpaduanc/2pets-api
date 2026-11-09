{{--
    DANFSe v2.0 (dompdf) — NT 008 v1.02, spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md §8.
    Só dados do XML da NFS-e (`DanfseView`). Tabelas (dompdf não tem flexbox/grid). Margem de 2 mm,
    borda de 1 pt, linhas de 0,5 pt, cabeçalho/títulos com fundo cinza 5%, rótulos 6 pt em negrito,
    conteúdo 7 pt. Fonte Helvetica (métrica da Arial; a NT pede Arial/Microsoft Sans Serif, que o
    dompdf não embute) — diferença registrada no spec.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>DANFSe {{ $danfse['identification']['access_key'] }}</title>
    <style>
        @page { margin: 2mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 7pt; color: #000; margin: 0; }
        .page { border: 1pt solid #000; }
        table { width: 100%; border-collapse: collapse; }
        td { border: 0.5pt solid #000; padding: 1.5pt 3pt; vertical-align: top; }
        .shade { background: #f2f2f2; }
        .block { font-size: 7pt; font-weight: bold; text-transform: uppercase; background: #f2f2f2; }
        .label { display: block; font-size: 6pt; font-weight: bold; }
        .id-label { display: block; font-size: 7pt; font-weight: bold; text-transform: uppercase; }
        .title { font-size: 9pt; font-weight: bold; text-align: center; }
        .invalid { color: #ff0000; font-size: 9pt; font-weight: bold; text-align: center; }
        .key { font-size: 9pt; letter-spacing: 0.5pt; }
        .small { font-size: 6pt; }
        .qr { text-align: center; width: 24%; }
        .qr img { width: 2.4cm; height: 2.4cm; }
        .description { height: 3.2cm; }
        .net { background: #f2f2f2; font-weight: bold; }
    </style>
</head>
<body>
<div class="page">
    <table>
        <tr class="shade">
            <td style="width: 24%"><span class="title">NFS-e</span><br><span class="small">Nota Fiscal de Serviço eletrônica</span></td>
            <td style="width: 50%">
                <div class="title">DANFSe v2.0</div>
                <div class="title">Documento Auxiliar da NFS-e</div>
                @if ($danfse['is_homologation'])
                    <div class="invalid">NFS-e SEM VALIDADE JURÍDICA</div>
                @endif
            </td>
            <td>
                <div>{{ $danfse['header']['municipality'] }}</div>
                <div class="small">{{ $danfse['header']['generator'] }}</div>
                <div class="small">{{ $danfse['header']['environment'] }}</div>
            </td>
        </tr>
    </table>

    @php($id = $danfse['identification'])
    <table>
        <tr>
            <td colspan="3"><span class="id-label">Chave de Acesso da NFS-e</span><span class="key">{{ $id['access_key'] }}</span></td>
            <td rowspan="4" class="qr">
                <img src="{{ $qrCode }}" alt="QR Code">
                <div class="small">A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e</div>
            </td>
        </tr>
        <tr>
            <td><span class="id-label">Número da NFS-e</span>{{ $id['number'] }}</td>
            <td><span class="id-label">Competência da NFS-e</span>{{ $id['competence'] }}</td>
            <td><span class="id-label">Data e Hora da Emissão da NFS-e</span>{{ $id['processed_at'] }}</td>
        </tr>
        <tr>
            <td><span class="id-label">Número da DPS</span>{{ $id['dps_number'] }}</td>
            <td><span class="id-label">Série da DPS</span>{{ $id['dps_series'] }}</td>
            <td><span class="id-label">Data e Hora da Emissão da DPS</span>{{ $id['dps_issued_at'] }}</td>
        </tr>
        <tr>
            <td class="shade"><span class="id-label">Emitente da NFS-e</span>{{ $id['issuer'] }}</td>
            <td><span class="id-label">Situação da NFS-e</span>{{ $id['status'] }}</td>
            <td><span class="id-label">Finalidade</span>{{ $id['purpose'] }}</td>
        </tr>
    </table>

    @php($provider = $danfse['provider'])
    <table>
        <tr><td colspan="4" class="block">Prestador / Fornecedor</td></tr>
        <tr>
            <td><span class="label">CNPJ / CPF / NIF</span>{{ $provider['document'] }}</td>
            <td><span class="label">Indicador Municipal</span>{{ $provider['municipal_registration'] }}</td>
            <td colspan="2"><span class="label">Telefone</span>{{ $provider['phone'] }}</td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">Nome / Nome Empresarial</span>{{ $provider['name'] }}</td>
            <td><span class="label">Município / Sigla UF</span>{{ $provider['city'] }}</td>
            <td><span class="label">Código IBGE / CEP</span>{{ $provider['codes'] }}</td>
        </tr>
        <tr>
            <td colspan="3"><span class="label">Endereço</span>{{ $provider['address'] }}</td>
            <td><span class="label">E-mail</span>{{ $provider['email'] }}</td>
        </tr>
    </table>

    <table>
        <tr><td colspan="3" class="block">Tomador / Adquirente da Operação</td></tr>
        @if ($danfse['taker'] === null)
            <tr><td colspan="3">TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e</td></tr>
        @else
            <tr>
                <td><span class="label">CNPJ / CPF / NIF</span>{{ $danfse['taker']['document'] }}</td>
                <td><span class="label">Nome / Nome Empresarial</span>{{ $danfse['taker']['name'] }}</td>
                <td><span class="label">Código IBGE / CEP</span>{{ $danfse['taker']['codes'] }}</td>
            </tr>
            <tr><td colspan="3"><span class="label">Endereço</span>{{ $danfse['taker']['address'] }}</td></tr>
        @endif
    </table>

    <table>
        <tr><td class="block">Destinatário da Operação</td></tr>
        <tr><td>O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO</td></tr>
        <tr><td class="block">Intermediário da Operação</td></tr>
        <tr><td>INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e</td></tr>
    </table>

    @php($service = $danfse['service'])
    <table>
        <tr><td colspan="3" class="block">Serviço Prestado</td></tr>
        <tr>
            <td><span class="label">Código de Tributação Nacional / Municipal</span>{{ $service['codes'] }}</td>
            <td><span class="label">Código da NBS</span>{{ $service['nbs'] }}</td>
            <td><span class="label">Local da Prestação</span>{{ $service['place'] }}</td>
        </tr>
        <tr><td colspan="3"><span class="label">Descrição do Código de Tributação Nacional / Municipal</span>{{ $service['national_description'] }}</td></tr>
        <tr><td colspan="3" class="description"><span class="label">Descrição do Serviço</span>{{ $service['description'] }}</td></tr>
    </table>

    @php($municipal = $danfse['municipal'])
    <table>
        <tr><td colspan="4" class="block">Tributação Municipal (ISSQN)</td></tr>
        <tr>
            <td><span class="label">Tipo de Tributação do ISSQN</span>{{ $municipal['taxation'] }}</td>
            <td><span class="label">Município de Incidência do ISSQN</span>{{ $municipal['incidence'] }}</td>
            <td><span class="label">Desconto Incondicionado</span>{{ $municipal['unconditional_discount'] }}</td>
            <td><span class="label">BC ISSQN</span>{{ $municipal['base'] }}</td>
        </tr>
        <tr>
            <td><span class="label">Alíquota Aplicada</span>{{ $municipal['rate'] }}</td>
            <td><span class="label">Retenção do ISSQN</span>{{ $municipal['withholding'] }}</td>
            <td colspan="2"><span class="label">ISSQN Apurado</span>{{ $municipal['iss'] }}</td>
        </tr>
    </table>

    @php($federal = $danfse['federal'])
    <table>
        <tr><td colspan="5" class="block">Tributação Federal (Exceto CBS)</td></tr>
        <tr>
            <td><span class="label">IRRF</span>{{ $federal['irrf'] }}</td>
            <td><span class="label">Contribuição Previdenciária - Retida</span>{{ $federal['social_security'] }}</td>
            <td><span class="label">Contribuições Sociais - Retidas</span>{{ $federal['social_contributions'] }}</td>
            <td><span class="label">PIS - Débito Apuração Própria</span>{{ $federal['pis'] }}</td>
            <td><span class="label">COFINS - Débito Apuração Própria</span>{{ $federal['cofins'] }}</td>
        </tr>
        <tr><td colspan="5" class="block">Tributação IBS / CBS</td></tr>
        <tr><td colspan="5">IBS/CBS NÃO INFORMADOS NESTA NFS-e</td></tr>
    </table>

    @php($totals = $danfse['totals'])
    <table>
        <tr><td colspan="5" class="block">Valor Total da NFS-e</td></tr>
        <tr>
            <td><span class="label">Valor da Operação / Serviço</span>{{ $totals['service'] }}</td>
            <td><span class="label">Desconto Incondicionado</span>{{ $totals['unconditional_discount'] }}</td>
            <td><span class="label">Desconto Condicionado</span>{{ $totals['conditional_discount'] }}</td>
            <td><span class="label">Total das Retenções</span>{{ $totals['withholdings'] }}</td>
            <td><span class="label">Valor Líquido da NFS-e</span>{{ $totals['net'] }}</td>
        </tr>
        <tr><td colspan="5" class="net"><span class="label">Valor Líquido da NFS-e + IBS/CBS</span>{{ $totals['net'] }}</td></tr>
    </table>

    <table>
        <tr><td class="block">Informações Complementares</td></tr>
        <tr><td class="description">{{ $danfse['complementary'] }}</td></tr>
    </table>
</div>
</body>
</html>
