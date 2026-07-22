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

        // TODO XADES: agregar aca el <Object><QualifyingProperties> con
        // SignedProperties (SigningTime + SigningCertificate) que exige el
        // perfil XAdES-BES del SIN, y una segunda Reference al SignedProperties.
        // Verificar contra el ejemplo firmado oficial antes de produccion.

        return $signature;
    }
}
