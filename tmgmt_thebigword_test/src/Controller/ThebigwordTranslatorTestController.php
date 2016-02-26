<?php

/**
 * @file
 * Contains \Drupal\tmgmt_thebigword_test\Controller\ThebigwordTranslatorTestController.
 */

namespace Drupal\tmgmt_thebigword_test\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns autocomplete responses for block categories.
 */
class ThebigwordTranslatorTestController {

  /**
   * List of projects.
   *
   * @var array
   */
  protected $projects = [];

  /**
   * List of files.
   *
   * @var array
   */
  protected $files = [];

  /**
   * Mock service - POST - used for creating a project.
   *
   * @param Request $request
   *   The request object.
   *
   * @return JsonResponse
   *   The JSON Response.
   */
  public function createProject(Request $request) {
    $data = array();
    parse_str($request->getContent(), $data);

    $data = Json::decode($data);
    $project_id = count($this->projects);
    $this->projects[$project_id] = [];

    return new JsonResponse(array(
      'opstat' => 'ok',
      'ProjectId' => $project_id,
      'response' => $data,
    ));
  }

  /**
   * Mock service - POST - used for uploading a file.
   *
   * @param Request $request
   *   The request object.
   *
   * @return JsonResponse
   *   The JSON Response.
   */
  public function file(Request $request) {
    $data = array();
    parse_str($request->getContent(), $data);

    $data = Json::decode($data);

    $file_id = count($this->projects[$data['ProjectId']]);
    $this->projects[$data['ProjectId']][] = $file_id;
    $this->files[$file_id] = $data;

    return new JsonResponse(array(
      'opstat' => 'ok',
      'FileId' => $file_id,
      'data' => $data,
    ));
  }

}
