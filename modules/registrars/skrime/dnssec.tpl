<h3>DNSSEC <small>{$domain|escape}</small></h3>

{if $successMessage}
    <div class="alert alert-success">{$successMessage|escape}</div>
{/if}
{if $errorMessage}
    <div class="alert alert-danger">{$errorMessage|escape}</div>
{/if}

{if $enabled}
    <div class="card panel panel-default mb-4">
        <div class="card-body panel-body">
            <h4>Aktueller Status</h4>
            {if $dnssec}
                <table class="table table-sm table-condensed">
                    <tr>
                        <th style="width:30%">Flags</th>
                        <td>{$dnssec.flags|escape}</td>
                    </tr>
                    <tr>
                        <th>Algorithmus</th>
                        <td>
                            {$dnssec.algorithm|escape}
                            {foreach $algorithms as $algorithmId => $algorithmName}
                                {if $algorithmId == $dnssec.algorithm}({$algorithmName|escape}){/if}
                            {/foreach}
                        </td>
                    </tr>
                    <tr>
                        <th>Public Key</th>
                        <td><code style="word-break:break-all">{$dnssec.publicKey|escape}</code></td>
                    </tr>
                </table>
                <form method="post" action="clientarea.php?action=domaindetails&amp;id={$domainid}&amp;modop=custom&amp;a=dnssec" onsubmit="return confirm('DNSSEC wirklich deaktivieren?');">
                    <input type="hidden" name="token" value="{$token}" />
                    <input type="hidden" name="skrime_action" value="delete" />
                    <button type="submit" class="btn btn-danger">DNSSEC deaktivieren</button>
                </form>
            {else}
                <p class="text-muted">DNSSEC ist für diese Domain nicht aktiv.</p>
            {/if}
        </div>
    </div>

    <div class="card panel panel-default">
        <div class="card-body panel-body">
            <h4>{if $dnssec}DNSKEY ändern{else}DNSSEC aktivieren{/if}</h4>
            <form method="post" action="clientarea.php?action=domaindetails&amp;id={$domainid}&amp;modop=custom&amp;a=dnssec">
                <input type="hidden" name="token" value="{$token}" />
                <input type="hidden" name="skrime_action" value="save" />
                <div class="form-group">
                    <label for="skrimeDnssecFlags">Flags</label>
                    <select name="flags" id="skrimeDnssecFlags" class="form-control">
                        {foreach $flagOptions as $flagValue => $flagLabel}
                            <option value="{$flagValue}"{if $dnssec && $dnssec.flags == $flagValue} selected{/if}>{$flagLabel|escape}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="form-group">
                    <label for="skrimeDnssecAlgorithm">Algorithmus</label>
                    <select name="algorithm" id="skrimeDnssecAlgorithm" class="form-control">
                        {foreach $algorithms as $algorithmId => $algorithmName}
                            <option value="{$algorithmId}"{if ($dnssec && $dnssec.algorithm == $algorithmId) || (!$dnssec && $algorithmId == 13)} selected{/if}>{$algorithmId} - {$algorithmName|escape}</option>
                        {/foreach}
                    </select>
                </div>
                <div class="form-group">
                    <label for="skrimeDnssecKey">Public Key</label>
                    <textarea name="publicKey" id="skrimeDnssecKey" class="form-control" rows="4" required>{if $dnssec}{$dnssec.publicKey|escape}{/if}</textarea>
                </div>
                <button type="submit" class="btn btn-primary">Speichern</button>
                <a href="clientarea.php?action=domaindetails&amp;id={$domainid}" class="btn btn-default btn-secondary">Zurück</a>
            </form>
        </div>
    </div>
{/if}
