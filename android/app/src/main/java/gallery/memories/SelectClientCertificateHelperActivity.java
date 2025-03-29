/*
 * Nextcloud Android Library
 *
 * SPDX-FileCopyrightText: 2023 Elv1zz <elv1zz.git@gmail.com>
 * SPDX-License-Identifier: MIT
 */
package gallery.memories;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.Dialog;
import android.content.Intent;
import android.os.Build;
import android.security.KeyChain;
import android.security.KeyChainAliasCallback;
import android.util.Log;
import androidx.annotation.Nullable;
import gallery.memories.network.AdvancedX509KeyManager;

public class SelectClientCertificateHelperActivity extends Activity implements KeyChainAliasCallback {

    private static final String TAG = SelectClientCertificateHelperActivity.class.getName();

    private static final int REQ_CODE_INSTALL_CERTS = 1;

    private int decisionId;
    private String hostname;
    private int port;

    private Dialog installCertsDialog = null;

    @Override
    public void onResume() {
        super.onResume();
        // Load data from intent
        Intent i = getIntent();
        decisionId = i.getIntExtra(AdvancedX509KeyManager.DECISION_INTENT_ID, AdvancedX509KeyManager.AKMDecision.DECISION_INVALID);
        hostname = i.getStringExtra(AdvancedX509KeyManager.DECISION_INTENT_HOSTNAME);
        port = i.getIntExtra(AdvancedX509KeyManager.DECISION_INTENT_PORT, -1);
        Log.d(TAG, "onResume() with " + i.getExtras() + " decId=" + decisionId + " data=" + i.getData());
        if (installCertsDialog == null) {
            KeyChain.choosePrivateKeyAlias(this, this, null, null, null, -1, null);
        }
    }

    /**
     * Called with the alias of the certificate chosen by the user, or null if no value was chosen.
     *
     * @param alias The alias of the certificate chosen by the user, or null if no value was chosen.
     */
    @Override
    public void alias(@Nullable String alias) {
        // Show a dialog to add a certificate if no certificate was found
        // API Versions < 29 still handle this automatically
        if (alias == null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            runOnUiThread(() -> {
                installCertsDialog = new AlertDialog.Builder(this)
                        .setTitle(R.string.title_no_client_cert)
                        .setMessage(R.string.message_install_client_cert)
                        .setPositiveButton(
                                android.R.string.yes,
                                (dialog, which) -> startActivityForResult(KeyChain.createInstallIntent(), REQ_CODE_INSTALL_CERTS)
                        )
                        .setNegativeButton(android.R.string.no, (dialog, which) -> {
                            dialog.dismiss();
                            sendDecision(AdvancedX509KeyManager.AKMDecision.DECISION_ABORT, null);
                        })
                        .create();
                installCertsDialog.show();
            });
        } else {
            sendDecision(AdvancedX509KeyManager.AKMDecision.DECISION_KEYCHAIN, alias);
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQ_CODE_INSTALL_CERTS) {
            installCertsDialog = null;
        } else {
            super.onActivityResult(requestCode, resultCode, data);
        }
    }

    /**
     * Stop the user interaction and send result to invoking AdvancedX509KeyManager.
     *
     * @param state type of the result as defined in AKMDecision
     * @param param keychain alias respectively keystore filename
     */
    void sendDecision(int state, String param) {
        Log.d(TAG, "sendDecision(" + state + ", " + param + ", " + hostname + ", " + port + ")");
        AdvancedX509KeyManager.interactResult(decisionId, state, param, hostname, port);
        finish();
    }
}
