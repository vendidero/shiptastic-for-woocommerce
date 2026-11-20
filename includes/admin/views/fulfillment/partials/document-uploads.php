<?php
/**
 * Documents of document steps (state.view.documents): uploaded file or not yet, upload/replace (PDF), hints (e.g. the
 * export declaration). Documents are optional unless marked as required (e.g. by Shiptastic Pro).
 */
defined( 'ABSPATH' ) || exit;
?>
<p class="stc-hint" data-wp-bind--hidden="!state.view.notice" data-wp-text="state.view.notice"></p>
<ul class="stc-document-list">
	<template data-wp-each--document="state.view.documents" data-wp-each-key="context.document.type">
		<li class="stc-document" data-wp-class--is-done="state.documents.hasFile" data-wp-class--is-missing="state.documents.isMissing">
			<div class="stc-document-main">
				<span class="stc-document-status" aria-hidden="true"></span>
				<div class="stc-document-body">
					<span class="stc-document-title">
						<strong data-wp-text="context.document.title"></strong>
						<span class="stc-tag" data-wp-bind--hidden="!context.document.required"><?php echo esc_html_x( 'Required', 'shipments-document', 'shiptastic-for-woocommerce' ); ?></span>
					</span>
					<a class="stc-document-file" target="_blank" rel="noopener" data-wp-bind--hidden="!state.documents.hasFile" data-wp-bind--href="state.documents.fileUrl" data-wp-text="state.documents.fileName"></a>
					<span class="stc-hint" data-wp-bind--hidden="state.documents.hasFile"><?php echo esc_html_x( 'Not uploaded yet', 'shipments-document', 'shiptastic-for-woocommerce' ); ?></span>
					<span class="stc-hint" data-wp-bind--hidden="!context.document.hint" data-wp-text="context.document.hint"></span>
				</div>
				<?php /* Documents created by extensions: edit their settings (sheet, e.g. a commercial invoice's customs data), recreate them. */ ?>
				<button type="button" class="stc-link-button" data-wp-bind--hidden="!context.document.editable" data-wp-on--click="actions.documents.openForm"><?php echo esc_html_x( 'Edit', 'shipments-document', 'shiptastic-for-woocommerce' ); ?></button>
				<button type="button" class="stc-icon-button stc-document-regenerate" data-wp-bind--hidden="!context.document.regenerable" data-wp-class--is-busy="state.documents.isRegenerating" data-wp-bind--disabled="state.documents.isRegenerating" data-wp-on--click="actions.documents.regenerate" title="<?php echo esc_attr_x( 'Recreate', 'shipments-document', 'shiptastic-for-woocommerce' ); ?>" aria-label="<?php echo esc_attr_x( 'Recreate', 'shipments-document', 'shiptastic-for-woocommerce' ); ?>">&#x21bb;</button>
				<label class="stc-button is-small stc-document-upload" data-wp-class--is-busy="state.documents.isUploading">
					<input type="file" class="screen-reader-text" accept="application/pdf,.pdf" data-wp-bind--disabled="state.documents.isUploading" data-wp-on--change="actions.documents.upload" />
					<span data-wp-text="state.documents.uploadLabel"></span>
				</label>
			</div>
		</li>
	</template>
</ul>

<?php /* Settings of a document (e.g. commercial invoice) in a sheet like the order details: saving (re-)creates the document. */ ?>
<div class="stc-sheet stc-document-sheet" role="dialog" aria-modal="true" data-wp-bind--hidden="!state.documents.sheet.open">
	<div class="stc-sheet-panel">
		<header class="stc-sheet-header">
			<h2 class="stc-sheet-title" data-wp-text="state.documents.sheet.title"></h2>
			<div class="stc-sheet-actions">
				<button type="button" class="stc-icon-button" data-wp-on--click="actions.documents.closeForm" aria-label="<?php echo esc_attr_x( 'Close', 'shipments', 'shiptastic-for-woocommerce' ); ?>">&times;</button>
			</div>
		</header>
		<div class="stc-sheet-body">
			<p class="stc-empty" data-wp-bind--hidden="!state.documents.sheet.loading"><?php echo esc_html_x( 'Loading…', 'shipments', 'shiptastic-for-woocommerce' ); ?></p>
			<div class="stc-card stc-label-form stc-document-form" data-wp-bind--hidden="state.documents.sheet.loading" data-wp-watch="callbacks.renderDocumentForm"></div>
		</div>
		<footer class="stc-sheet-footer">
			<button type="button" class="stc-button" data-wp-on--click="actions.documents.closeForm"><?php echo esc_html_x( 'Cancel', 'shipments', 'shiptastic-for-woocommerce' ); ?></button>
			<button type="button" class="stc-button is-primary is-large" data-wp-on--click="actions.documents.saveForm" data-wp-bind--disabled="state.documents.isSheetBusy" data-wp-text="state.documents.saveLabel"></button>
		</footer>
	</div>
</div>
