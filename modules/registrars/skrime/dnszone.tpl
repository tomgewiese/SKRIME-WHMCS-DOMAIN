<h3>DNS-Verwaltung <small>{$domain|escape}</small></h3>

{if $successMessage}
    <div class="alert alert-success">{$successMessage|escape}</div>
{/if}
{if $errorMessage}
    <div class="alert alert-danger">{$errorMessage|escape}</div>
{/if}

{if $enabled && $loaded}
    <form method="post" action="clientarea.php?action=domaindetails&amp;id={$domainid}&amp;modop=custom&amp;a=dnszone">
        <input type="hidden" name="token" value="{$token}" />
        <input type="hidden" name="skrime_action" value="savezone" />
        <input type="hidden" name="zone_hash" value="{$zoneHash|escape}" />
        <div class="table-responsive">
            <table class="table table-striped table-sm table-condensed">
                <thead>
                    <tr>
                        <th style="width:22%">Name</th>
                        <th style="width:14%">Typ</th>
                        <th style="width:10%">Priorität</th>
                        <th>Wert</th>
                        <th style="width:8%" class="text-center">Löschen</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $rows as $index => $row}
                        <tr>
                            <td><input type="text" name="dns_name[{$index}]" value="{$row.name|escape}" class="form-control input-sm form-control-sm" /></td>
                            <td>
                                <select name="dns_type[{$index}]" class="form-control input-sm form-control-sm">
                                    {foreach $types as $type}
                                        <option value="{$type}"{if $row.type == $type} selected{/if}>{$type}</option>
                                    {/foreach}
                                </select>
                            </td>
                            <td><input type="text" name="dns_priority[{$index}]" value="{$row.priority|escape}" class="form-control input-sm form-control-sm" placeholder="MX/SRV" /></td>
                            <td><input type="text" name="dns_data[{$index}]" value="{$row.data|escape}" class="form-control input-sm form-control-sm" /></td>
                            <td class="text-center"><input type="checkbox" name="dns_delete[{$index}]" value="1" /></td>
                        </tr>
                    {/foreach}
                    {for $new = 1 to $newRows}
                        {assign var="newIndex" value="new`$new`"}
                        <tr>
                            <td><input type="text" name="dns_name[{$newIndex}]" value="" class="form-control input-sm form-control-sm" placeholder="@ oder www" /></td>
                            <td>
                                <select name="dns_type[{$newIndex}]" class="form-control input-sm form-control-sm">
                                    {foreach $types as $type}
                                        <option value="{$type}">{$type}</option>
                                    {/foreach}
                                </select>
                            </td>
                            <td><input type="text" name="dns_priority[{$newIndex}]" value="" class="form-control input-sm form-control-sm" placeholder="MX/SRV" /></td>
                            <td><input type="text" name="dns_data[{$newIndex}]" value="" class="form-control input-sm form-control-sm" placeholder="Neuer Eintrag" /></td>
                            <td></td>
                        </tr>
                    {/for}
                </tbody>
            </table>
        </div>
        <input type="hidden" name="dns_complete" value="1" />
        <button type="submit" class="btn btn-primary">Zone speichern</button>
        <a href="clientarea.php?action=domaindetails&amp;id={$domainid}" class="btn btn-default btn-secondary">Zurück</a>
    </form>

    <div class="card panel panel-default mt-4" style="margin-top:20px">
        <div class="card-body panel-body">
            <h4>Hinweise</h4>
            <table class="table table-sm table-condensed" style="margin-bottom:0">
                <tr><td><strong>A / AAAA</strong></td><td>IPv4- bzw. IPv6-Adresse, z. B. 192.0.2.10</td></tr>
                <tr><td><strong>CNAME / ALIAS</strong></td><td>Zielname, z. B. ziel.domain.tld</td></tr>
                <tr><td><strong>MX</strong></td><td>Priorität (z. B. 10) und Mailserver, z. B. mail.domain.tld</td></tr>
                <tr><td><strong>SRV</strong></td><td>Priorität und "Gewichtung Port Ziel", z. B. 5 5060 sip.domain.tld; Name z. B. _sip._tcp</td></tr>
                <tr><td><strong>TXT</strong></td><td>Freier Text, z. B. v=spf1 mx ~all</td></tr>
                <tr><td><strong>CAA</strong></td><td>z. B. 0 issue "letsencrypt.org"</td></tr>
                <tr><td><strong>TLSA / DS / DNSKEY / PTR</strong></td><td>Wert im üblichen Zonendatei-Format</td></tr>
            </table>
            <p class="text-muted" style="margin:10px 0 0">Leere Zeilen werden ignoriert. Es wird immer die komplette Zone gespeichert.</p>
        </div>
    </div>
{/if}
