<?php declare(strict_types=1);

namespace Nais\Device\Approval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

#[CoversClass(SamlRequest::class)]
class SamlRequestTest extends TestCase
{
    public function testCanPresentAsString(): void
    {
        /** @var SimpleXMLElement */
        $request = simplexml_load_string((string) gzinflate((string) base64_decode((string) new SamlRequest('some-issuer'), true)), 'SimpleXMLElement', 0, 'samlp');
        $request->registerXPathNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        /** @var array<SimpleXMLElement> */
        $elems = $request->xpath('/samlp:AuthnRequest/saml:Issuer');

        $this->assertSame('some-issuer', (string) $elems[0]);
    }
}
