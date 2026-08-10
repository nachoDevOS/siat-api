<?php

namespace App\Services\Factura;

use App\Exceptions\SiatException;
use App\Models\Certificado;
use DOMDocument;

/**
 * Firma un XML con el certificado .p12 de la empresa (firma digital enveloped,
 * RSA-SHA256, XML-DSig).
 *
 * Esta clase implementa el NUCLEO mecanico de la firma: calcula el digest del
 * documento, arma el SignedInfo, lo canonicaliza (C14N nativo de PHP) y lo
 * firma con la clave privada del .p12, incrustando el bloque <Signature>.
 *
 * IMPORTANTE: el SIN exige el perfil XAdES-BES, que agrega un bloque
 * <QualifyingProperties> con la referencia al certificado y la fecha de firma.
 * Ese bloque debe completarse y validarse contra el ejemplo oficial del SIN
 * antes de produccion (marcado con TODO XADES mas abajo). La firma XML-DSig
 * base que se genera aca ya es verificable localmente.
 */
class FirmadorXml
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Namespace del perfil XAdES. PENDIENTE DE VERIFICAR: hay dos versiones en
     * uso (1.3.2 y 1.4.1) y el SIN no documenta cual exige. Se confirma con el
     * XML de ejemplo oficial.
     */
    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * Firma el XML y devuelve el documento firmado como string.
     *
     * @throws SiatException si el .p12 no se puede abrir con su passphrase.
     */
    public function firmar(string $xml, Certificado $certificado): string
    {
        [$clavePrivada, $certX509] = $this->abrirCertificado($certificado);

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xml);

        // 1. Digest del documento completo (referencia con URI vacia = todo el doc).
        $c14nDocumento = $doc->documentElement->C14N();
        $digestDocumento = base64_encode(hash('sha256', $c14nDocumento, true));

        // 2. Armar el SignedInfo con esa referencia.
        $signedInfo = $this->construirSignedInfo($doc, $digestDocumento);

        // 3. Canonicalizar el SignedInfo y firmarlo con la clave privada.
        $c14nSignedInfo = $signedInfo->C14N();
        openssl_sign($c14nSignedInfo, $firmaBinaria, $clavePrivada, OPENSSL_ALGO_SHA256);
        $signatureValue = base64_encode($firmaBinaria);

        // 4. Armar el bloque <Signature> completo e incrustarlo en el documento.
        $signature = $this->construirSignature($doc, $signedInfo, $signatureValue, $certX509);
        $doc->documentElement->appendChild($signature);

        return $doc->saveXML();
    }

    /**
     * Abre el .p12 cifrado y devuelve [clavePrivada, certificadoX509 en base64].
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function abrirCertificado(Certificado $certificado): array
    {
        // El contenido viene en base64 (asi se guardo cifrado en la base).
        $p12Binario = base64_decode($certificado->contenido_p12, true);

        $almacen = [];

        if ($p12Binario === false || ! openssl_pkcs12_read($p12Binario, $almacen, $certificado->passphrase)) {
            throw new SiatException('No se pudo abrir el certificado .p12 (passphrase incorrecta o archivo invalido).');
        }

        $clavePrivada = openssl_pkey_get_private($almacen['pkey']);

        if ($clavePrivada === false) {
            throw new SiatException('El certificado no contiene una clave privada valida.');
        }

        // Dejamos el certificado X509 sin las lineas PEM para el nodo X509Certificate.
        $certLimpio = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $almacen['cert']);

        return [$clavePrivada, $certLimpio];
    }

    private function construirSignedInfo(DOMDocument $doc, string $digest): \DOMElement
    {
        $signedInfo = $doc->createElementNS(self::NS_DSIG, 'SignedInfo');

        $c14n = $doc->createElementNS(self::NS_DSIG, 'CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($c14n);

        $signatureMethod = $doc->createElementNS(self::NS_DSIG, 'SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($signatureMethod);

        // Referencia con URI vacia: la firma cubre todo el documento (enveloped).
        $reference = $doc->createElementNS(self::NS_DSIG, 'Reference');
        $reference->setAttribute('URI', '');

        $transforms = $doc->createElementNS(self::NS_DSIG, 'Transforms');
        $transform = $doc->createElementNS(self::NS_DSIG, 'Transform');
        $transform->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($transform);
        $reference->appendChild($transforms);

        $digestMethod = $doc->createElementNS(self::NS_DSIG, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        $digestValue = $doc->createElementNS(self::NS_DSIG, 'DigestValue');
        $digestValue->appendChild($doc->createTextNode($digest));
        $reference->appendChild($digestValue);

        $signedInfo->appendChild($reference);

        return $signedInfo;
    }

    private function construirSignature(
        DOMDocument $doc,
        \DOMElement $signedInfo,
        string $signatureValue,
        string $certX509,
    ): \DOMElement {
        $signature = $doc->createElementNS(self::NS_DSIG, 'Signature');

        // El SignedInfo ya construido se mueve dentro del Signature.
        $signature->appendChild($signedInfo);

        $sigValue = $doc->createElementNS(self::NS_DSIG, 'SignatureValue');
        $sigValue->appendChild($doc->createTextNode($signatureValue));
        $signature->appendChild($sigValue);

        $keyInfo = $doc->createElementNS(self::NS_DSIG, 'KeyInfo');
        $x509Data = $doc->createElementNS(self::NS_DSIG, 'X509Data');
        $x509Cert = $doc->createElementNS(self::NS_DSIG, 'X509Certificate');
        $x509Cert->appendChild($doc->createTextNode($certX509));
        $x509Data->appendChild($x509Cert);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        // TODO XADES: enchufar aca construirQualifyingProperties() y agregar en
        // construirSignedInfo() la segunda Reference que apunta al
        // SignedProperties (URI="#{id}-signedprops", Type="...#SignedProperties").
        //
        // NO se enchufa todavia a proposito: la forma exacta del bloque debe
        // contrastarse contra un XML firmado de ejemplo oficial del SIN. Un
        // bloque con la estructura equivocada hace rechazar TODAS las facturas,
        // que es peor que la firma XML-DSig base que hoy se genera.

        return $signature;
    }

    /**
     * ESQUELETO SIN VERIFICAR — no se usa todavia.
     *
     * Arma el bloque <Object><QualifyingProperties> del perfil XAdES-BES:
     * la fecha de firma y la huella del certificado firmante, atados al
     * <Signature> por su Id.
     *
     * Todo lo dudoso esta concentrado en este unico metodo. Lo que falta
     * confirmar contra el ejemplo oficial del SIN antes de usarlo:
     *
     *   - Version del namespace XAdES (1.3.2 vs 1.4.1).
     *   - Si el SIN espera tambien <SignedDataObjectProperties>.
     *   - Si el DigestMethod del certificado es SHA-256 o SHA-1.
     *   - El formato exacto de <X509IssuerName> (orden de los RDN).
     *   - Si el Id del Signature y del SignedProperties siguen algun patron fijo.
     *
     * @param  string  $certX509  certificado en base64, sin cabeceras PEM.
     * @param  string  $idFirma  Id del elemento <Signature> al que se ata.
     */
    private function construirQualifyingProperties(
        DOMDocument $doc,
        string $certX509,
        string $idFirma,
    ): \DOMElement {
        $object = $doc->createElementNS(self::NS_DSIG, 'Object');

        $qualifying = $doc->createElementNS(self::NS_XADES, 'xades:QualifyingProperties');
        $qualifying->setAttribute('Target', "#{$idFirma}");

        $signedProperties = $doc->createElementNS(self::NS_XADES, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', "{$idFirma}-signedprops");

        $signatureProperties = $doc->createElementNS(self::NS_XADES, 'xades:SignedSignatureProperties');

        // Momento de la firma, en formato ISO 8601.
        $signingTime = $doc->createElementNS(self::NS_XADES, 'xades:SigningTime');
        $signingTime->appendChild($doc->createTextNode(now()->toAtomString()));
        $signatureProperties->appendChild($signingTime);

        // Huella del certificado firmante + su emisor y numero de serie.
        $binarioCert = base64_decode($certX509, true) ?: '';
        $datosCert = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n"
            .chunk_split($certX509, 64, "\n")
            ."-----END CERTIFICATE-----\n") ?: [];

        $signingCertificate = $doc->createElementNS(self::NS_XADES, 'xades:SigningCertificate');
        $cert = $doc->createElementNS(self::NS_XADES, 'xades:Cert');

        $certDigest = $doc->createElementNS(self::NS_XADES, 'xades:CertDigest');
        $digestMethod = $doc->createElementNS(self::NS_DSIG, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $certDigest->appendChild($digestMethod);

        $digestValue = $doc->createElementNS(self::NS_DSIG, 'DigestValue');
        $digestValue->appendChild($doc->createTextNode(base64_encode(hash('sha256', $binarioCert, true))));
        $certDigest->appendChild($digestValue);
        $cert->appendChild($certDigest);

        $issuerSerial = $doc->createElementNS(self::NS_XADES, 'xades:IssuerSerial');
        $issuerName = $doc->createElementNS(self::NS_DSIG, 'X509IssuerName');
        $issuerName->appendChild($doc->createTextNode($this->nombreEmisor($datosCert)));
        $issuerSerial->appendChild($issuerName);

        $serialNumber = $doc->createElementNS(self::NS_DSIG, 'X509SerialNumber');
        $serialNumber->appendChild($doc->createTextNode((string) ($datosCert['serialNumber'] ?? '')));
        $issuerSerial->appendChild($serialNumber);
        $cert->appendChild($issuerSerial);

        $signingCertificate->appendChild($cert);
        $signatureProperties->appendChild($signingCertificate);

        $signedProperties->appendChild($signatureProperties);
        $qualifying->appendChild($signedProperties);
        $object->appendChild($qualifying);

        return $object;
    }

    /**
     * Reconstruye el nombre distinguido del emisor (CN=..., O=..., C=...).
     * El orden de los componentes es justamente uno de los puntos a confirmar.
     *
     * @param  array<string, mixed>  $datosCert  salida de openssl_x509_parse.
     */
    private function nombreEmisor(array $datosCert): string
    {
        $partes = [];

        foreach ((array) ($datosCert['issuer'] ?? []) as $clave => $valor) {
            $partes[] = $clave.'='.(is_array($valor) ? implode(',', $valor) : $valor);
        }

        return implode(', ', $partes);
    }
}
