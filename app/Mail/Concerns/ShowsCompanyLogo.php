<?php

namespace App\Mail\Concerns;

use App\Models\Company;

/**
 * The customer's own logo at the top of a mail, sent along rather than linked.
 *
 * Nearly every mail program blocks outside images until the reader allows
 * them, and then the first thing a customer sees is a broken picture. An
 * embedded image has no such threshold. A link would not work anyway: the file
 * sits on the tenant's disk, and the url that reaches it needs a login.
 *
 * The path is passed and not the contents: emails.partials.company-logo hangs
 * it on with embed(), and that can only be done there. The url is for a
 * preview in the browser, where there is no message to embed anything in.
 */
trait ShowsCompanyLogo
{
    /** @return array{company_name: ?string, logo_file: ?string, logo_url: ?string} */
    protected function companyLogo(): array
    {
        $company = Company::main();
        $file = $company?->logoFile();

        return [
            'company_name' => $company?->name,
            'logo_file' => $file,
            'logo_url' => $file ? route('files.companyLogo', $company) : null,
        ];
    }
}
