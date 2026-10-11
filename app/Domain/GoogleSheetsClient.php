<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Fetches Google Sheets as CSV.
 *
 * Public sheets need nothing but the export URL. Private sheets require an
 * OAuth2 access token obtained via a service account — the token is supplied
 * by the caller and never persisted to the database or echoed to the client.
 */
final class GoogleSheetsClient
{
    public function __construct(
        private ?string $accessToken = null,
        private int $timeout = 30
    ) {
    }

    /**
     * Build the CSV export URL for a sheet + tab gid.
     */
    public static function exportUrl(string $spreadsheetId, ?string $gid = null): string
    {
        $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($spreadsheetId) . '/export?format=csv';
        if ($gid !== null && $gid !== '') {
            $url .= '&gid=' . rawurlencode($gid);
        }
        return $url;
    }

    /**
     * Extract spreadsheet id and gid from a full /edit URL.
     *
     * @return array{id:string, gid:?string}|null
     */
    public static function parseUrl(string $url): ?array
    {
        if (!preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m)) {
            return null;
        }
        $gid = null;
        if (preg_match('/[#?&]gid=(\d+)/', $url, $g)) {
            $gid = $g[1];
        }
        return ['id' => $m[1], 'gid' => $gid];
    }

    /**
     * @return array{ok:bool, csv?:string, error?:string, status?:int}
     */
    public function fetchCsv(string $spreadsheetId, ?string $gid = null): array
    {
        $url = self::exportUrl($spreadsheetId, $gid);

        $headers = [
            'User-Agent: AmayaLogIntelligence/1.0 (+operations reporting)',
        ];
        if ($this->accessToken !== null && $this->accessToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'follow_location'=> 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false) {
            return ['ok' => false, 'error' => 'Could not reach Google Sheets.', 'status' => $status ?: 0];
        }
        if ($status === 401 || $status === 403) {
            return [
                'ok'     => false,
                'status' => $status,
                'error'  => 'This spreadsheet is private. Provide a service-account OAuth token, or set the sheet to "Anyone with the link can view".',
            ];
        }
        if ($status >= 400) {
            return ['ok' => false, 'status' => $status, 'error' => "Google Sheets returned HTTP {$status}."];
        }
        // A 200 that returns the sign-in page means access is still blocked.
        if ($this->looksLikeSignInPage($body)) {
            return [
                'ok'     => false,
                'status' => 401,
                'error'  => 'Google returned a sign-in page instead of CSV. The sheet is not publicly readable.',
            ];
        }

        return ['ok' => true, 'csv' => $body, 'status' => $status];
    }

    private function looksLikeSignInPage(string $body): bool
    {
        $head = substr($body, 0, 2000);
        return stripos($head, '<!doctype html') === 0
            && (stripos($head, 'accounts.google.com') !== false || stripos($head, 'ServiceLogin') !== false);
    }
}