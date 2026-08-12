@can('refreshReadModel', $project)
    <form method="POST"
          action="{{ route('projects.ticket-read-model.refresh', $project) }}"
          class="ai6-refresh-form">
        @csrf
        <input type="hidden" name="operation_id" value="{{ $operationId }}">
        <input type="hidden" name="relative_path" value="{{ $viewModel->readModel->relative_path }}">
        <button type="submit">Refresh beauftragen</button>
    </form>
@endcan
