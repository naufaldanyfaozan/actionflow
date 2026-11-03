<nav class="app-header navbar navbar-expand bg-body">

    <div class="container-fluid">

        <ul class="navbar-nav">
            <li class="nav-item">
                <a
                    class="nav-link"
                    data-lte-toggle="sidebar"
                    href="#"
                    role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
        </ul>

        <ul class="navbar-nav ms-auto">
            <?php if (isset($_SESSION['pf_username'])): ?>
                <li class="nav-item">
                    <a href="/productionflow/dashboard.php" class="nav-link" title="Ganti Modul">
                        <i class="bi bi-arrow-left-right"></i>
                        <span class="d-none d-md-inline">Ganti Modul</span>
                    </a>
                </li>
            <?php endif; ?>
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <span class="d-none d-md-inline">User Login : <b><?php echo $_SESSION["username"] ?></b></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <li class="user-header text-bg-primary">
                        <p>Departemen :
                            <small></small>
                        </p>
                    </li>

                    <li class="user-footer">
                        <a href="logout.php" class="btn btn-default btn-flat float-end">Sign out</a>
                    </li>
                </ul>
            </li>
        </ul>

    </div>

</nav>