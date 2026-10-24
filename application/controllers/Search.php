<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Read-only, role-scoped record search for the global Find dialog.
 *
 * The controller remains deliberately small: authorization is enforced by
 * MY_Controller::ACCESS_MATRIX and record-level scoping lives in Search_model.
 */
class Search extends MY_Controller {

    public $Search_model;

    public function __construct() {
        parent::__construct();
        $this->load->model('Search_model');
    }

    public function index() {
        if (strtolower((string)$this->input->method()) !== 'get') {
            $this->output->set_status_header(405);
            $this->output->set_header('Allow: GET');
            $this->json(array('ok' => FALSE, 'error' => 'Method Not Allowed'), 405);
            return;
        }

        $q = trim((string)$this->input->get('q', TRUE));
        if (function_exists('mb_substr')) {
            $q = mb_substr($q, 0, 100, 'UTF-8');
        } else {
            $q = substr($q, 0, 100);
        }

        if ((function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) < 2) {
            $this->json(array(
                'ok'      => TRUE,
                'query'   => $q,
                'groups'  => array(),
                'total'   => 0,
                'message' => 'Enter at least 2 characters to find records.',
            ));
            return;
        }

        try {
            $groups = $this->Search_model->find($q, $this->user_id, $this->role_id);
            $total  = 0;
            foreach ($groups as $group) $total += count($group['results']);

            $this->output->set_header('Cache-Control: no-store');
            $this->json(array(
                'ok'     => TRUE,
                'query'  => $q,
                'groups' => $groups,
                'total'  => $total,
            ));
        } catch (Throwable $e) {
            log_message('error', 'Global Find failed: '.$e->getMessage());
            $this->json(array(
                'ok'    => FALSE,
                'error' => 'Search could not be completed. Please try again.',
            ), 500);
        }
    }
}
