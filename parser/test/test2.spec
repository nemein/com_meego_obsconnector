%bcond_with mp3

Name:           scummvm-tools
Version:        1.2.0
Release:        1
Summary:        ScummVM-related tools
Group:          Amusements/Games/Other
License:        GPLv2+
URL:            http://www.scummvm.org
Source0:        %{name}-%{version}.tar.gz
Source1:        %{name}.desktop
BuildRoot:      %{_tmppath}/%{name}-%{version}-build
BuildRequires:  wxGTK-devel flac-devel libvorbis-devel zlib-devel gcc-c++
BuildRequires:  desktop-file-utils
%if %{with mp3}
BuildRequires:  libmad-devel
%endif

%description
This is a collection of various tools that may be useful to use in
conjunction with ScummVM.
Please note that although a tool may support a feature, certain ScummVM
versions may not. ScummVM 0.6.x does not support FLAC audio, for example.

Many games package together all their game data in a few big archive files.
The following tools can be used to extract these archives, and in some cases
are needed to make certain game versions usable with ScummVM.

The following tools can also be used to analyze the game scripts
(controlling the behavior of certain scenes and actors in a game).
These tools are most useful to developers.

%prep
%setup -q

%build
CXXFLAGS="${CXXFLAGS:-%optflags}" ; export CXXFLAGS
./configure --prefix=%{_prefix} \
            --bindir=%{_bindir} \
            --mandir=%{_mandir} \
            --libdir=%{_libdir} \
            --enable-verbose-build
make %{?jobs:-j%jobs}

%install
mkdir -p ${RPM_BUILD_ROOT}%{_bindir}
make install DESTDIR=${RPM_BUILD_ROOT}
install -p -D -m 644 gui/media/scummvmtools_128.png ${RPM_BUILD_ROOT}%{_datadir}/icons/hicolor/128x128/apps/%{name}.png
install -d %{buildroot}/usr/share/applications/
install %{_sourcedir}/%{name}.desktop %{buildroot}/usr/share/applications/%{name}.desktop

%clean
rm -rf ${RPM_BUILD_ROOT}

%post
touch --no-create %{_datadir}/icons/hicolor || :
if [ -x %{_bindir}/gtk-update-icon-cache ]; then
        %{_bindir}/gtk-update-icon-cache --quiet %{_datadir}/icons/hicolor || :
fi

%postun
touch --no-create %{_datadir}/icons/hicolor || :
if [ -x %{_bindir}/gtk-update-icon-cache ]; then
        %{_bindir}/gtk-update-icon-cache --quiet %{_datadir}/icons/hicolor || :
fi

%files
%defattr(0644,root,root,0755)
%doc COPYING README TODO
%attr(0755,root,root) %{_bindir}/*
%{_datadir}/%{name}
%{_datadir}/icons/hicolor/128x128/apps/%{name}.png
%{_datadir}/applications/%{name}.desktop

%changelog
