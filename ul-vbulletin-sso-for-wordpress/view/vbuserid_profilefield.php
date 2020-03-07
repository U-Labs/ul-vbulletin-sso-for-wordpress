<h3>
    <?php echo __( 'vBulletin-Account' ); ?>
</h3>
<table class="form-table">
    <tr>
        <th>
            <label for="related_vbuserid">
                <?php echo __( 'Verknüpfte vB-UserId' ); ?>
            </label>
        </th>
        <td>
            <input type="text" id="related_vbuserid" name="related_vbuserid" value="<?php echo $related_vbuserid; ?>" />
            <span class="description" style="display: block; margin-top: 10px">
                <?php echo __( 'Ist der Nutzer mit diesem Konto in vB angemeldet, wird er in Wordpress automatisch eingeloggt.' ); ?><br>
                <b style="color:red">
                    <?php echo __( 'Dieses Feld wird vom Plugin selbstständig gepflegt und sollte NUR in Ausnahmefällen manuell angepasst werden!' ); ?>
                </b>
            </span>
        </td>
    </tr>
</table>